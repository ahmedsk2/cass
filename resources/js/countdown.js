// Progressive enhancement: the server already prints the deadline in the
// conference timezone, this only appends a live "x days, y hours" counter.
//
// No English lives in this file. __() cannot reach a module, and the sentence
// this used to assemble - `days === 1 ? '' : 's'` - is an English rule that
// Arabic, with six plural forms, cannot use. So the view hands the element
// whole phrases on data- attributes and this script picks one and substitutes
// the numbers.
function remaining(deadline) {
    const ms = deadline.getTime() - Date.now();

    if (ms <= 0) {
        return null;
    }

    const minutes = Math.floor(ms / 60000);

    return {
        days: Math.floor(minutes / 1440),
        hours: Math.floor((minutes % 1440) / 60),
        minutes: minutes % 60,
    };
}

// Whole phrases from data- attributes, chosen by magnitude and by count, and
// substituted here. The strings are not in this file on purpose: __() cannot
// reach a module, and `days === 1 ? '' : 's'` is an English rule that Arabic -
// with six plural forms - cannot use.
const phrase = (element, key, replacements) => {
    const template = element.dataset[key] ?? '';

    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
        template,
    );
};

const sentence = (element, left) => {
    if (left.days > 0) {
        return phrase(element, left.days === 1 ? 'countdownDaysOne' : 'countdownDays', {
            days: left.days,
            hours: left.hours,
        });
    }

    if (left.hours > 0) {
        return phrase(element, left.hours === 1 ? 'countdownHoursOne' : 'countdownHours', {
            hours: left.hours,
            minutes: left.minutes,
        });
    }

    return phrase(element, left.minutes === 1 ? 'countdownMinutesOne' : 'countdownMinutes', {
        minutes: left.minutes,
    });
};

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

    // An element with no phrases on it has nothing to say, so the badge goes
    // away rather than showing a bare separator. That is the old behaviour for
    // a passed deadline, and it is still the fallback for one.
    const render = (text) => {
        if (text === '') {
            badge.remove();

            return;
        }

        badge.textContent = `· ${text}`;
    };

    const tick = () => {
        const left = remaining(deadline);

        if (left === null) {
            finished = true;
            render(phrase(element, 'countdownPassed', {}));

            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }

            return;
        }

        render(sentence(element, left));
    };

    tick();

    if (!finished) {
        timer = window.setInterval(tick, 60000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-countdown]').forEach(start);
});
