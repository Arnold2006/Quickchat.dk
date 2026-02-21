# QuickChat — Setup Guide for aapanel + Ubuntu 25.x

A no-registration anonymous chat server.  
10 themed rooms · 20 users per room · Private "red" channels · Black/grey/orange theme.

---

## Stack

| Layer         | Technology                     |
|---------------|-------------------------------|
| Runtime       | Node.js 20 LTS                |
| Real-time     | Socket.io 4                   |
| Web server    | Express (static + API)        |
| Reverse proxy | Apache (aapanel)              |
| Storage       | In-memory (no DB needed)      |

---

## 1. Install Node.js on your server

```bash
# Via nvm (recommended — works alongside aapanel)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20
node -v    # should show v20.x.x
```

---

## 2. Upload and install the app

```bash
# Copy the chat-server folder to your server, e.g.:
scp -r chat-server/ root@your-server-ip:/www/wwwroot/quickchat/

# On the server:
cd /www/wwwroot/quickchat
npm install
```

---

## 3. Run as a service with PM2 (so it survives reboots)

```bash
npm install -g pm2

# Start
pm2 start server.js --name quickchat

# Auto-start on boot
pm2 startup
pm2 save

# Useful commands
pm2 logs quickchat       # live logs
pm2 restart quickchat    # restart
pm2 status               # overview
```

---

## 4. Configure Apache reverse proxy in aapanel

### Enable required Apache modules (run once):
```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers
sudo systemctl restart apache2
```

### In aapanel:
1. Go to **Website** → **Add Site**
2. Set your domain (e.g. `chat.yourdomain.com`)
3. After creation, click **Config** → **Apache config**
4. Replace the contents with the `apache.conf` file provided
5. Change `chat.yourdomain.com` to your actual domain
6. Save and reload Apache

### Free SSL (recommended):
In aapanel, go to your site → **SSL** → **Let's Encrypt** → issue a certificate.  
Then uncomment the HTTPS block in `apache.conf`.

---

## 5. Open firewall port (if needed)

The Node app runs on port **3000** internally — Apache proxies it, so port 3000
does NOT need to be open externally. Only ports 80 and 443 should be public.

If aapanel has a firewall panel, make sure **80** and **443** are open.

---

## 6. Customise the app

### Change room names/themes
Edit the `ROOM_DEFS` array in `server.js`:
```js
const ROOM_DEFS = [
  { name: 'General',   icon: '💬', description: 'Just talk about anything' },
  // ... 9 more rows
];
```

### Change limits
```js
const MAX_ROOMS          = 10;   // total rooms
const MAX_USERS_PER_ROOM = 20;   // users per room
const MAX_MSG_HISTORY    = 100;  // messages kept in RAM per room
const MAX_MSG_LENGTH     = 500;  // max characters per message
```

### Change welcome text / site name
Edit `public/index.html` — search for `QuickChat` and `welcome-strip`.

### Change colours
Edit the `:root { }` block at the top of the `<style>` section in `index.html`.

---

## How private ("red") chat works

1. In a room, click any username → a menu pops up with **🔴 Private chat**
2. A red panel opens at the bottom-right of the screen
3. The other user gets an invite banner — they click **Accept**
4. Only those two users can see the messages (separate Socket.io room)
5. Messages are in-memory only — they vanish when both close the panel

---

## Notes

- **No database required** — all state is in RAM. Messages are lost on restart.
- If you want persistent message history, add a MySQL/SQLite layer to `server.js`.
- The server handles up to ~1000 concurrent sockets comfortably on a basic VPS.
- Nicknames are unique per room only (same nick can exist in different rooms).

---

## File structure

```
quickchat/
├── server.js          ← Node.js + Socket.io backend
├── package.json       ← dependencies
├── apache.conf        ← Apache reverse proxy config
├── README.md          ← this file
└── public/
    └── index.html     ← complete single-file frontend
```
