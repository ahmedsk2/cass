// Progressive enhancement: the server already prints the deadline in the
// conference timezone, this only appends a live "x days, y hours" counter.
function remaining(deadline) {
    const ms = deadline.getTime() - Date.now();

    if (ms <= 0) {
        return null;
    }

    const minutes = Math.floor(ms / 60000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    if (days > 0) {
        return `${days} day${days === 1 ? '' : 's'}, ${hours} hour${hours === 1 ? '' : 's'} left`;
    }

    return `${hours} hour${hours === 1 ? '' : 's'}, ${minutes % 60} minute${minutes % 60 === 1 ? '' : 's'} left`;
}

function start(element) {
    const deadline = new Date(element.dataset.deadline);

    if (Number.isNaN(deadline.getTime())) {
        return;
    }

    const badge = document.createElement('span');
    badge.className = 'ml-2 font-medium text-[var(--org-accent)]';
    element.after(badge);

    // Both declared before the first tick(): a deadline that is already past
    // when the page loads takes the finished branch synchronously, and reading
    // a `const timer` declared below it would throw ReferenceError out of the
    // temporal dead zone - aborting the DOMContentLoaded handler and with it
    // every other countdown on the page.
    let timer = null;
    let finished = false;

    const tick = () => {
        const text = remaining(deadline);

        if (text === null) {
            finished = true;
            badge.remove();

            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }

            return;
        }

        badge.textContent = `· ${text}`;
    };

    tick();

    if (!finished) {
        timer = window.setInterval(tick, 60000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-countdown]').forEach(start);
});
