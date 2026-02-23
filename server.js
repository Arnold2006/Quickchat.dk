/**
 * QuickChat Server
 * Anonymous multi-room chat with private "red" messaging
 * Stack: Node.js + Express + Socket.io
 */

const express = require('express');
const http    = require('http');
const { Server } = require('socket.io');
const path    = require('path');
const crypto  = require('crypto');

const app    = express();
const server = http.createServer(app);
const io     = new Server(server, {
  cors: { origin: process.env.ALLOWED_ORIGIN || false },
  maxHttpBufferSize: 4 * 1024 * 1024
});

// ─── Config ──────────────────────────────────────────────────────────────────
const MAX_ROOMS          = 10;
const MAX_USERS_PER_ROOM = 20;
const MAX_MSG_HISTORY    = 100;
const MAX_MSG_LENGTH     = 500;
const PORT               = process.env.PORT || 3000;

// ─── Security headers ─────────────────────────────────────────────────────────
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('X-XSS-Protection', '1; mode=block');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
  next();
});

// ─── Rate limiting (login brute-force protection) ─────────────────────────────
const loginAttempts = new Map(); // ip → { count, resetAt }
function checkLoginRate(ip) {
  const now  = Date.now();
  const entry = loginAttempts.get(ip) || { count: 0, resetAt: now + 15 * 60 * 1000 };
  if (now > entry.resetAt) { entry.count = 0; entry.resetAt = now + 15 * 60 * 1000; }
  entry.count++;
  loginAttempts.set(ip, entry);
  return entry.count <= 10; // max 10 attempts per 15 minutes
}
// Clean up old entries every 30 minutes
setInterval(() => {
  const now = Date.now();
  for (const [ip, e] of loginAttempts) if (now > e.resetAt) loginAttempts.delete(ip);
}, 30 * 60 * 1000);

// ─── Password / Auth ──────────────────────────────────────────────────────────
const fs           = require('fs');
const AUTH_FILE    = path.join(__dirname, 'auth.json');
const DEFAULT_PASS = 'admin123'; // First-run default — change via admin page!

// PBKDF2 helpers (no external deps needed)
function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.pbkdf2Sync(password, salt, 310000, 32, 'sha256').toString('hex');
  return { salt, hash };
}

function verifyPassword(password, salt, hash) {
  const check = crypto.pbkdf2Sync(password, salt, 310000, 32, 'sha256').toString('hex');
  return crypto.timingSafeEqual(Buffer.from(check, 'hex'), Buffer.from(hash, 'hex'));
}

// Load or initialise auth store
function loadAuth() {
  if (fs.existsSync(AUTH_FILE)) {
    try { return JSON.parse(fs.readFileSync(AUTH_FILE, 'utf8')); } catch(e) {}
  }
  // First run — hash the default password and save it
  const { salt, hash } = hashPassword(DEFAULT_PASS);
  const auth = { salt, hash };
  fs.writeFileSync(AUTH_FILE, JSON.stringify(auth, null, 2));
  console.log(`🔑 Første start: standard admin-adgangskode er "${DEFAULT_PASS}" — skift den venligst i administrationspanelet!`);
  return auth;
}

let auth = loadAuth();

app.use(express.json({ limit: '5mb' })); // allow larger payloads for room icons

// ─── Room definitions ─────────────────────────────────────────────────────────
const ROOM_DEFS = [
  { name: 'General',      icon: '💬', description: 'Just talk about anything'         },
  { name: 'Tech Talk',    icon: '💻', description: 'Gadgets, code and all things tech' },
  { name: 'Music Lounge', icon: '🎵', description: 'Share your sounds and playlists'   },
  { name: 'Gaming Zone',  icon: '🎮', description: 'Games, streams and high scores'    },
  { name: 'Sports',       icon: '⚽', description: 'Live scores and fan chat'          },
  { name: 'Movies',       icon: '🎬', description: 'Reviews, trailers and spoilers'    },
  { name: 'Travel',       icon: '✈️',  description: 'Tips, photos and adventures'       },
  { name: 'Food',         icon: '🍕', description: 'Recipes, restaurants and cravings' },
  { name: 'Art & Design', icon: '🎨', description: 'Creative minds welcome'            },
  { name: 'Random',       icon: '🎲', description: 'Anything goes in here'             },
];

// ─── Room persistence ─────────────────────────────────────────────────────────
const ROOMS_FILE = path.join(__dirname, 'rooms.json');

function saveRoomsToDisk() {
  const data = Object.values(rooms).map(r => ({
    id: r.id, name: r.name, icon: r.icon, iconImage: r.iconImage || null, description: r.description,
  }));
  fs.writeFileSync(ROOMS_FILE, JSON.stringify(data, null, 2));
}

function loadRoomsFromDisk() {
  if (!fs.existsSync(ROOMS_FILE)) return null;
  try { return JSON.parse(fs.readFileSync(ROOMS_FILE, 'utf8')); } catch(e) { return null; }
}

// ─── In-memory state ──────────────────────────────────────────────────────────
const rooms = {};
const savedRooms = loadRoomsFromDisk();

if (savedRooms && savedRooms.length > 0) {
  // Restore from disk
  savedRooms.forEach(r => {
    rooms[r.id] = { id: r.id, name: r.name, icon: r.icon, iconImage: r.iconImage || null, description: r.description, users: {}, messages: [] };
  });
} else {
  // First run — use defaults
  ROOM_DEFS.forEach((def, i) => {
    const id = `room${i + 1}`;
    rooms[id] = { id, ...def, iconImage: null, users: {}, messages: [] };
  });
}

const users        = {};  // socketId → { nickname, roomId }
const privateRooms = {};  // prId     → { users: {socketId: nick}, messages: [] }

// ─── Helpers ──────────────────────────────────────────────────────────────────
function roomSnapshot(room) {
  const count = Object.keys(room.users).length;
  return {
    id:          room.id,
    name:        room.name,
    icon:        room.icon,
    iconImage:   room.iconImage || null,
    description: room.description,
    count,
    max:       MAX_USERS_PER_ROOM,
    available: count < MAX_USERS_PER_ROOM,
  };
}

function allRoomSnapshots() {
  return Object.values(rooms).map(roomSnapshot);
}

function broadcastRoomList() {
  io.emit('room-list-update', allRoomSnapshots());
}

function makeMessage(nickname, text, type = 'public') {
  return {
    id:        crypto.randomUUID(),
    nickname,
    message:   type === 'image' ? text : text.substring(0, MAX_MSG_LENGTH),
    timestamp: Date.now(),
    type,
  };
}

function systemMessage(text) {
  return makeMessage('System', text, 'system');
}

// ─── Socket events ────────────────────────────────────────────────────────────
io.on('connection', (socket) => {

  // --- Initial room list ---
  socket.on('get-rooms', () => {
    socket.emit('room-list', allRoomSnapshots());
  });

  // --- Join a room ---
  socket.on('join-room', ({ roomId, nickname }) => {
    // Validate inputs
    nickname = (nickname || '').trim().substring(0, 20);
    if (!nickname || !/^[a-zA-Z0-9_\-]+$/.test(nickname)) {
      return socket.emit('join-error', 'Kaldenavn skal være 1-20 tegn: bogstaver, tal, _ eller -');
    }

    const room = rooms[roomId];
    if (!room) return socket.emit('join-error', 'Rummet blev ikke fundet.');

    if (Object.keys(room.users).length >= MAX_USERS_PER_ROOM) {
      return socket.emit('join-error', 'Dette rum er fuldt (20/20). Prøv et andet rum!');
    }

    // Nickname uniqueness within the room
    const takenNicks = Object.values(room.users).map(u => u.nickname.toLowerCase());
    if (takenNicks.includes(nickname.toLowerCase())) {
      return socket.emit('join-error', 'Det kaldenavn er allerede taget i dette rum. Vælg venligst et andet.');
    }

    // Leave previous room if switching
    if (users[socket.id]) {
      const prevRoom = rooms[users[socket.id].roomId];
      if (prevRoom) {
        delete prevRoom.users[socket.id];
        socket.leave(prevRoom.id);
        const sysMsg = systemMessage(`${users[socket.id].nickname} forlod rummet.`);
        prevRoom.messages.push(sysMsg);
        io.to(prevRoom.id).emit('new-message', sysMsg);
        io.to(prevRoom.id).emit('user-list', Object.values(prevRoom.users).map(u => u.nickname));
      }
    }

    // Register user
    room.users[socket.id] = { nickname, socketId: socket.id };
    users[socket.id]      = { nickname, roomId };
    socket.join(roomId);

    // Confirm to joiner
    socket.emit('room-joined', {
      roomId,
      roomName:      room.name,
      roomIcon:      room.icon,
      roomIconImage: room.iconImage || null,
      description:   room.description,
      messages:      room.messages.slice(-MAX_MSG_HISTORY),
      users:         Object.values(room.users).map(u => u.nickname),
    });

    // Announce to room
    const sysMsg = systemMessage(`${nickname} trådte ind i rummet.`);
    room.messages.push(sysMsg);
    io.to(roomId).emit('new-message', sysMsg);
    io.to(roomId).emit('user-list', Object.values(room.users).map(u => u.nickname));

    broadcastRoomList();
  });

  // --- Leave a room voluntarily ---
  socket.on('leave-room', () => {
    const user = users[socket.id];
    if (user && user.roomId) {
      const room = rooms[user.roomId];
      if (room && room.users[socket.id]) {
        delete room.users[socket.id];
        socket.leave(user.roomId);
        const sysMsg = systemMessage(`${user.nickname} forlod rummet.`);
        room.messages.push(sysMsg);
        io.to(user.roomId).emit('new-message', sysMsg);
        io.to(user.roomId).emit('user-list', Object.values(room.users).map(u => u.nickname));
      }
      delete users[socket.id];
      broadcastRoomList();
    }
  });

  // --- Send public message ---
  socket.on('send-message', ({ message }) => {
    const user = users[socket.id];
    if (!user || !user.roomId) return;
    const room = rooms[user.roomId];
    if (!room || !room.users[socket.id]) return;

    // Rate limit: max 5 messages per 2 seconds per user
    const now = Date.now();
    if (!user.msgTimes) user.msgTimes = [];
    user.msgTimes = user.msgTimes.filter(t => now - t < 2000);
    if (user.msgTimes.length >= 5) return; // silently drop
    user.msgTimes.push(now);

    const msg = makeMessage(user.nickname, message);
    room.messages.push(msg);
    if (room.messages.length > MAX_MSG_HISTORY) room.messages.shift();
    io.to(user.roomId).emit('new-message', msg);
  });

  // --- Start private chat ---
  socket.on('start-private', ({ targetNickname }) => {
    const user = users[socket.id];
    if (!user || !user.roomId) return;

    const room = rooms[user.roomId];
    if (!room) return;

    const targetEntry = Object.entries(room.users).find(
      ([, u]) => u.nickname.toLowerCase() === targetNickname.toLowerCase()
    );
    if (!targetEntry) return socket.emit('chat-error', 'Brugeren blev ikke fundet i dette rum.');

    const [targetSocketId, targetUser] = targetEntry;
    if (targetSocketId === socket.id) return socket.emit('chat-error', 'Du kan ikke chatte privat med dig selv!');

    // Stable private room ID (alphabetical sort so both sides get same ID)
    const prId = [socket.id, targetSocketId].sort().join('::');

    if (!privateRooms[prId]) {
      privateRooms[prId] = {
        id:       prId,
        users:    { [socket.id]: user.nickname, [targetSocketId]: targetUser.nickname },
        messages: [],
      };
    }

    // Make both sockets join the private channel
    socket.join(prId);
    const targetSocket = io.sockets.sockets.get(targetSocketId);
    if (targetSocket) targetSocket.join(prId);

    // Tell initiator
    socket.emit('private-started', {
      prId,
      withNickname: targetUser.nickname,
      messages: privateRooms[prId].messages,
    });

    // Invite target
    io.to(targetSocketId).emit('private-invited', {
      prId,
      fromNickname: user.nickname,
    });
  });

  // --- Send private message ---
  socket.on('send-private', ({ prId, message, type }) => {
    const user = users[socket.id];
    const pr   = privateRooms[prId];
    if (!user || !pr || !pr.users[socket.id]) return;

    // For image messages, validate it looks like a data URL
    if (type === 'image') {
      if (!message || !message.startsWith('data:image/')) return;
      if (message.length > 4 * 1024 * 1024) return; // 4MB hard limit
    }

    const msg = makeMessage(user.nickname, message, type || 'private');
    pr.messages.push(msg);
    if (pr.messages.length > MAX_MSG_HISTORY) pr.messages.shift();
    io.to(prId).emit('new-private-message', { prId, msg });
  });

  // --- Close private chat (one side) ---
  socket.on('close-private', ({ prId }) => {
    const user = users[socket.id];
    const pr   = privateRooms[prId];
    if (!pr) return;

    // Notify the other party
    const otherSocketId = Object.keys(pr.users).find(id => id !== socket.id);
    if (otherSocketId) {
      io.to(otherSocketId).emit('private-closed', { prId, byNickname: user?.nickname });
    }
    socket.leave(prId);
    // Clean up if both left
    setTimeout(() => {
      const room = io.sockets.adapter.rooms.get(prId);
      if (!room || room.size === 0) delete privateRooms[prId];
    }, 5000);
  });

  // --- Disconnect ---
  socket.on('disconnect', () => {
    const user = users[socket.id];
    if (user && user.roomId) {
      const room = rooms[user.roomId];
      if (room && room.users[socket.id]) {
        delete room.users[socket.id];
        const sysMsg = systemMessage(`${user.nickname} forlod rummet.`);
        room.messages.push(sysMsg);
        io.to(user.roomId).emit('new-message', sysMsg);
        io.to(user.roomId).emit('user-list', Object.values(room.users).map(u => u.nickname));
      }
    }
    delete users[socket.id];
    broadcastRoomList();
  });
});

// ─── Admin API ────────────────────────────────────────────────────────────────

// Simple token — a signed timestamped token stored in memory
// (good enough for a single-admin panel; no JWT dependency needed)
let sessionToken = null;
let sessionExpiry = 0;

function generateToken() {
  sessionToken  = crypto.randomBytes(32).toString('hex');
  sessionExpiry = Date.now() + 8 * 60 * 60 * 1000; // 8 hours
  return sessionToken;
}

function adminAuth(req, res, next) {
  const token = req.headers['x-admin-token'];
  if (!token || token !== sessionToken || Date.now() > sessionExpiry) {
    return res.status(401).json({ error: 'Unauthorized' });
  }
  // Slide the expiry on activity
  sessionExpiry = Date.now() + 8 * 60 * 60 * 1000;
  next();
}

// Login
app.post('/admin/login', (req, res) => {
  const ip = req.headers['x-forwarded-for']?.split(',')[0]?.trim() || req.socket.remoteAddress;
  if (!checkLoginRate(ip)) {
    return res.status(429).json({ error: 'For mange forsøg — prøv igen om 15 minutter' });
  }
  const { password } = req.body;
  if (!password) return res.status(400).json({ error: 'No password provided' });
  try {
    const ok = verifyPassword(password, auth.salt, auth.hash);
    if (ok) {
      loginAttempts.delete(ip); // reset on success
      res.json({ ok: true, token: generateToken() });
    } else {
      res.status(401).json({ error: 'Wrong password' });
    }
  } catch(e) {
    res.status(500).json({ error: 'Auth error' });
  }
});

// Change password
app.post('/admin/change-password', adminAuth, (req, res) => {
  const { currentPassword, newPassword } = req.body;
  if (!currentPassword || !newPassword) {
    return res.status(400).json({ error: 'Missing fields' });
  }
  if (newPassword.length < 8) {
    return res.status(400).json({ error: 'New password must be at least 8 characters' });
  }
  // Verify current password first
  const ok = verifyPassword(currentPassword, auth.salt, auth.hash);
  if (!ok) return res.status(401).json({ error: 'Current password is wrong' });

  // Hash and save new password
  const { salt, hash } = hashPassword(newPassword);
  auth = { salt, hash };
  fs.writeFileSync(AUTH_FILE, JSON.stringify(auth, null, 2));

  // Invalidate current session so they log in again with new password
  sessionToken  = null;
  sessionExpiry = 0;

  res.json({ ok: true });
});

// Get all rooms
app.get('/admin/rooms', adminAuth, (req, res) => {
  res.json(Object.values(rooms).map(r => ({
    id:          r.id,
    name:        r.name,
    icon:        r.icon,
    iconImage:   r.iconImage || null,
    description: r.description,
    userCount:   Object.keys(r.users).length,
  })));
});

// Reorder rooms — must be BEFORE /:roomId routes or Express matches "reorder" as a roomId
app.post('/admin/rooms/reorder', adminAuth, (req, res) => {
  const { order } = req.body;
  if (!Array.isArray(order)) return res.status(400).json({ error: 'order skal være et array' });

  const reordered = {};
  order.forEach(id => { if (rooms[id]) reordered[id] = rooms[id]; });
  Object.keys(rooms).forEach(id => { if (!reordered[id]) reordered[id] = rooms[id]; });
  Object.keys(rooms).forEach(id => delete rooms[id]);
  Object.assign(rooms, reordered);

  saveRoomsToDisk();
  broadcastRoomList();
  res.json({ ok: true });
});

// Update a single room
app.put('/admin/rooms/:roomId', adminAuth, (req, res) => {
  const room = rooms[req.params.roomId];
  if (!room) return res.status(404).json({ error: 'Room not found' });

  const { name, icon, iconImage, description } = req.body;
  if (name)                      room.name        = String(name).substring(0, 32);
  if (icon)                      room.icon        = String(icon).substring(0, 8);
  if (description)               room.description = String(description).substring(0, 80);

  // iconImage: accept a base64 data URL, or null to clear it
  if (iconImage === null)        room.iconImage = null;
  if (iconImage && iconImage.startsWith('data:image/')) {
    if (iconImage.length > 2 * 1024 * 1024) return res.status(400).json({ error: 'Ikon-billede for stort (maks 2MB)' });
    room.iconImage = iconImage;
  }

  saveRoomsToDisk();
  broadcastRoomList();

  io.to(room.id).emit('room-updated', {
    roomId:      room.id,
    name:        room.name,
    icon:        room.icon,
    iconImage:   room.iconImage || null,
    description: room.description,
  });

  res.json({ ok: true });
});

// Kick all users from a room
app.post('/admin/rooms/:roomId/kick', adminAuth, (req, res) => {
  const room = rooms[req.params.roomId];
  if (!room) return res.status(404).json({ error: 'Room not found' });

  const msg = systemMessage('En administrator har ryddet dette rum. Vend venligst tilbage.');
  io.to(room.id).emit('new-message', msg);
  io.to(room.id).emit('kicked');

  Object.keys(room.users).forEach(sid => {
    delete users[sid];
    const s = io.sockets.sockets.get(sid);
    if (s) s.leave(room.id);
  });
  room.users    = {};
  room.messages = [];

  broadcastRoomList();
  res.json({ ok: true });
});

// Create a new room
app.post('/admin/rooms', adminAuth, (req, res) => {
  const { name, icon, description } = req.body;
  if (!name) return res.status(400).json({ error: 'Name is required' });

  // Generate next available room ID
  const existingIds = Object.keys(rooms).map(id => parseInt(id.replace('room', ''))).filter(n => !isNaN(n));
  const nextNum = existingIds.length > 0 ? Math.max(...existingIds) + 1 : 1;
  const id = `room${nextNum}`;

  rooms[id] = {
    id,
    name:        String(name).substring(0, 32),
    icon:        String(icon || '💬').substring(0, 8),
    iconImage:   null,
    description: String(description || '').substring(0, 80),
    users:       {},
    messages:    [],
  };

  saveRoomsToDisk();
  broadcastRoomList();
  res.json({ ok: true, id });
});

// Delete a room
app.delete('/admin/rooms/:roomId', adminAuth, (req, res) => {
  const room = rooms[req.params.roomId];
  if (!room) return res.status(404).json({ error: 'Room not found' });

  // Kick everyone out first
  const msg = systemMessage('Dette rum er blevet slettet af en administrator.');
  io.to(room.id).emit('new-message', msg);
  io.to(room.id).emit('kicked');

  Object.keys(room.users).forEach(sid => {
    delete users[sid];
    const s = io.sockets.sockets.get(sid);
    if (s) s.leave(room.id);
  });

  delete rooms[req.params.roomId];
  saveRoomsToDisk();
  broadcastRoomList();
  res.json({ ok: true });
});
app.get('/admin/stats', adminAuth, (req, res) => {
  res.json({
    totalConnected: Object.keys(users).length,
    rooms: Object.values(rooms).map(r => ({
      id:        r.id,
      name:      r.name,
      userCount: Object.keys(r.users).length,
      users:     Object.values(r.users).map(u => u.nickname),
    })),
  });
});

// ─── Ads API ──────────────────────────────────────────────────────────────────
const ADS_FILE = path.join(__dirname, 'ads.json');

function loadAds() {
  if (fs.existsSync(ADS_FILE)) {
    try { return JSON.parse(fs.readFileSync(ADS_FILE, 'utf8')); } catch(e) {}
  }
  return { left: '', right: '' };
}

function saveAds(ads) {
  fs.writeFileSync(ADS_FILE, JSON.stringify(ads, null, 2));
}

// Public endpoint — index.html fetches this on load
app.get('/ads.json', (req, res) => {
  res.json(loadAds());
});

// Admin: get current ads
app.get('/admin/ads', adminAuth, (req, res) => {
  res.json(loadAds());
});

// Admin: save ads
app.put('/admin/ads', adminAuth, (req, res) => {
  const { left, right } = req.body;
  const ads = {
    left:  typeof left  === 'string' ? left  : '',
    right: typeof right === 'string' ? right : '',
  };
  saveAds(ads);
  res.json({ ok: true });
});

// ─── Contact messages ─────────────────────────────────────────────────────────
const CONTACT_FILE = path.join(__dirname, 'contact.json');

function loadContact() {
  if (fs.existsSync(CONTACT_FILE)) {
    try { return JSON.parse(fs.readFileSync(CONTACT_FILE, 'utf8')); } catch(e) {}
  }
  return [];
}

function saveContact(msgs) {
  fs.writeFileSync(CONTACT_FILE, JSON.stringify(msgs, null, 2));
}

// Public POST — any user can submit
app.post('/contact', (req, res) => {
  const { message } = req.body;
  if (!message || typeof message !== 'string') return res.status(400).json({ error: 'Ingen besked' });
  const text = message.trim().substring(0, 1000);
  if (!text) return res.status(400).json({ error: 'Tom besked' });

  const msgs = loadContact();
  msgs.push({ id: crypto.randomUUID(), message: text, timestamp: Date.now() });
  saveContact(msgs);
  res.json({ ok: true });
});

// Admin: get all messages
app.get('/admin/contact', adminAuth, (req, res) => {
  res.json(loadContact());
});

// Admin: delete one message
app.delete('/admin/contact/:id', adminAuth, (req, res) => {
  const msgs = loadContact().filter(m => m.id !== req.params.id);
  saveContact(msgs);
  res.json({ ok: true });
});

// Admin: delete all messages
app.delete('/admin/contact', adminAuth, (req, res) => {
  saveContact([]);
  res.json({ ok: true });
});

// Serve admin explicitly BEFORE static middleware so Apache rewrite rules can't intercept it
app.get('/admin.html', (req, res) => {
  res.sendFile(path.join(__dirname, 'admin.html'));
});

app.use(express.static(path.join(__dirname, 'public')));

// ─── Start ────────────────────────────────────────────────────────────────────
server.listen(PORT, () => {
  console.log(`✅ QuickChat running on http://localhost:${PORT}`);
});
