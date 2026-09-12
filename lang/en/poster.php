<?php

declare(strict_types=1);

/*
 * The printable QR poster (spec 5.7). Three strings, and they are the only
 * English on a sheet that is otherwise the conference's own words.
 *
 * `deadline` is the whole line rather than a label beside a date: the date and
 * the timezone are placeholders, so a translation can put them in the order its
 * language reads them. The view passes the date already wrapped in the accent
 * <strong>, escaped, which is why it is echoed unescaped there.
 */

return [

    'submit' => 'Submit your abstract',
    'deadline' => 'Deadline :date (:timezone)',
    'scan' => 'Scan the code or type the address above.',

];
