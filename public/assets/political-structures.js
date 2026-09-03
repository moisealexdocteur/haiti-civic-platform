(() => {
    const input = document.querySelector('[data-structure-search]');
    const rows = Array.from(document.querySelectorAll('[data-structure-row]'));
    const empty = document.querySelector('[data-structure-empty]');

    if (!input || rows.length === 0) {
        return;
    }

    const normalize = (value) => value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase();

    input.addEventListener('input', () => {
        const query = normalize(input.value.trim());
        let visible = 0;

        rows.forEach((row) => {
            const haystack = normalize(row.dataset.search || row.textContent || '');
            const show = query === '' || haystack.includes(query);
            row.hidden = !show;

            if (show) {
                visible += 1;
            }
        });

        if (empty) {
            empty.hidden = visible !== 0;
        }
    });
})();
