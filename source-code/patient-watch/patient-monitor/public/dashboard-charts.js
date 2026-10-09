(() => {
    const init = root => {
        if (!root || root.dataset.chartReady === 'true') return;
        root.dataset.chartReady = 'true';

        const days = [...root.querySelectorAll('.activity-day')];
        const activityPoints = [...root.querySelectorAll('.activity-point')];
        const statusRows = [...root.querySelectorAll('.status-row')];
        const statusCard = root.querySelector('.status-card');
        const donut = root.querySelector('.status-donut');
        const feedback = document.createElement('span');

        feedback.className = 'chart-feedback';
        feedback.setAttribute('aria-live', 'polite');
        root.append(feedback);

        days.forEach(day => {
            day.tabIndex = 0;
            day.setAttribute('role', 'button');
            day.dataset.chartTarget = 'day';
            const point = activityPoints.find(item => item.dataset.dayIndex === day.dataset.dayIndex);
            day.addEventListener('mouseenter', () => point?.classList.add('is-hovered'));
            day.addEventListener('mouseleave', () => point?.classList.remove('is-hovered'));
            day.addEventListener('focus', () => point?.classList.add('is-hovered'));
            day.addEventListener('blur', () => point?.classList.remove('is-hovered'));
        });

        statusRows.forEach(row => {
            row.tabIndex = 0;
            row.setAttribute('role', 'button');
            row.dataset.chartTarget = 'status';
        });

        const replay = (element, className) => {
            if (!element) return;
            element.classList.remove(className);
            void element.offsetWidth;
            element.classList.add(className);
            window.setTimeout(() => element.classList.remove(className), 720);
        };

        const announce = message => {
            feedback.textContent = '';
            window.requestAnimationFrame(() => { feedback.textContent = message; });
        };

        const selectDay = day => {
            const wasSelected = day.classList.contains('is-selected');
            days.forEach(item => item.classList.remove('is-selected'));
            activityPoints.forEach(point => point.classList.remove('is-selected'));

            if (wasSelected) {
                announce('Daily alert selection cleared.');
                return;
            }

            day.classList.add('is-selected');
            const point = activityPoints.find(item => item.dataset.dayIndex === day.dataset.dayIndex);
            point?.classList.add('is-selected');
            replay(day, 'is-pulsing');
            replay(root.querySelector('.activity-card'), 'is-pulsing');
            announce(day.getAttribute('aria-label') || 'Daily alert activity selected.');
        };

        const selectStatus = row => {
            const wasSelected = row.classList.contains('is-selected');
            statusRows.forEach(item => item.classList.remove('is-selected'));
            statusCard?.classList.remove('is-pulsing');

            if (wasSelected) {
                announce('Incident status selection cleared.');
                return;
            }

            row.classList.add('is-selected');
            replay(row, 'is-pulsing');
            replay(statusCard, 'is-pulsing');
            replay(donut, 'is-pulsing');
            const label = row.querySelector('span')?.textContent.trim() || 'Incident status';
            const count = row.querySelector('strong')?.textContent.trim() || '0';
            announce(label + ': ' + count + '.');
        };

        const activate = target => {
            if (target.dataset.chartTarget === 'day') selectDay(target);
            if (target.dataset.chartTarget === 'status') selectStatus(target);
        };

        root.addEventListener('click', event => {
            const target = event.target.closest('.activity-day, .status-row');
            if (target && root.contains(target)) activate(target);
        });

        root.addEventListener('keydown', event => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const target = event.target.closest('.activity-day, .status-row');
            if (!target || !root.contains(target)) return;
            event.preventDefault();
            activate(target);
        });
    };

    window.PatientWatchDashboardCharts = { init };
    init(document.querySelector('.dashboard-charts'));
})();
