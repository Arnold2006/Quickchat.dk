    </main>

    <footer class="site-footer">
        <p>100 % anonymt &nbsp;·&nbsp; ingen registrering &nbsp;·&nbsp; ingen logning</p>
    </footer>
</div>

<script>
// Sæt sessionStorage-flag så velkomst-modalen springes over ved Hjem-navigation
document.querySelectorAll('a[data-home-link="1"]').forEach(function (a) {
    a.addEventListener('click', function () {
        try { sessionStorage.setItem('qc_skip_modal', '1'); } catch (e) {}
    });
});
</script>
</body>
</html>
