<?php

// Command line only. tests/ is denied in .htaccess as well, for the same
// reason tools/ is: every file here runs against the live ems_db,
// so reached over the web they would let anyone alter it.
if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../deadlines.php";

echo "deadlines\n";

// Every boundary in this feature is a day apart, so the day is injected
// rather than waited for. A fixed date keeps the arithmetic readable.
$today = '2026-10-13';
$d = function($offset) use ($today){
    return date('Y-m-d', strtotime($today . ' ' . $offset));
};

// --- what closes a deadline ----------------------------------------------
t_ok('Pending leaves it open', deadline_is_open('Pending'));
t_ok('Not Submitted leaves it open', deadline_is_open('Not Submitted'));
t_ok('Needs Revision leaves it open', deadline_is_open('Needs Revision'));
t_ok('Rejected leaves it open', deadline_is_open('Rejected'));
t_ok('Submitted closes it', !deadline_is_open('Submitted'));
t_ok('Approved closes it', !deadline_is_open('Approved'));
t_ok('Completed closes it', !deadline_is_open('Completed'));
// The case nobody thinks of: an office can add its own statuses.
t_ok('a custom office status leaves it open', deadline_is_open('In Progress'));
t_ok('an empty status leaves it open', deadline_is_open(''));

// --- reading one row ------------------------------------------------------
t_equals('no date reads as none', deadline_state(['deadline' => null, 'status' => 'Pending'], $today), 'none');
t_equals('an empty date reads as none', deadline_state(['deadline' => '', 'status' => 'Pending'], $today), 'none');
t_equals('a zero date reads as none', deadline_state(['deadline' => '0000-00-00', 'status' => 'Pending'], $today), 'none');
t_equals('yesterday is overdue', deadline_state(['deadline' => $d('-1 day'), 'status' => 'Pending'], $today), 'overdue');
t_equals('today is not overdue', deadline_state(['deadline' => $today, 'status' => 'Pending'], $today), 'soon');
t_equals('tomorrow is soon', deadline_state(['deadline' => $d('+1 day'), 'status' => 'Pending'], $today), 'soon');

// The warning window boundary, which is the likeliest off-by-one here.
$warn = deadline_warn_days();
t_equals('exactly the warning window away is still soon',
    deadline_state(['deadline' => $d("+{$warn} days"), 'status' => 'Pending'], $today), 'soon');
t_equals('one day past the warning window is future',
    deadline_state(['deadline' => $d('+' . ($warn + 1) . ' days'), 'status' => 'Pending'], $today), 'future');

// A finished recommendation is never overdue, however old the date.
t_equals('submitted outranks a past date', deadline_state(['deadline' => $d('-40 days'), 'status' => 'Submitted'], $today), 'met');
t_equals('completed outranks a past date', deadline_state(['deadline' => $d('-40 days'), 'status' => 'Completed'], $today), 'met');
t_equals('sent back after the date is overdue again',
    deadline_state(['deadline' => $d('-40 days'), 'status' => 'Needs Revision'], $today), 'overdue');

// --- the bucket SQL -------------------------------------------------------
// Asserted as behaviour, not as strings: each bucket must narrow to its own
// dates and must exclude finished work, or the filter is useless for chasing.
foreach([DEADLINE_FILTER_OVERDUE, DEADLINE_FILTER_WEEK, DEADLINE_FILTER_MONTH, DEADLINE_FILTER_LATER] as $bucket){
    t_ok("the {$bucket} bucket excludes finished work", strpos(deadline_filter_sql($bucket), "status NOT IN") !== false);
    t_ok("the {$bucket} bucket requires a date", strpos(deadline_filter_sql($bucket), "deadline IS NOT NULL") !== false);
}
t_ok('the none bucket asks for rows with no date', deadline_filter_sql(DEADLINE_FILTER_NONE) === '(deadline IS NULL)');
t_equals('an unknown bucket narrows nothing', deadline_filter_sql('nonsense'), '');
t_equals('an empty bucket narrows nothing', deadline_filter_sql(''), '');
t_ok('every offered bucket produces a fragment', count(array_filter(array_keys(deadline_filter_options()), function($b){
    return deadline_filter_sql($b) !== '';
})) === count(deadline_filter_options()));

// --- the sort -------------------------------------------------------------
t_ok('soonest first puts undated rows last', strpos(deadline_sort_sql(DEADLINE_SORT_SOONEST), 'deadline IS NULL,') === 0);
t_ok('latest first puts undated rows last too', strpos(deadline_sort_sql(DEADLINE_SORT_LATEST), 'deadline IS NULL,') === 0);
t_ok('soonest first is ascending', strpos(deadline_sort_sql(DEADLINE_SORT_SOONEST), 'deadline ASC') !== false);
t_ok('latest first is descending', strpos(deadline_sort_sql(DEADLINE_SORT_LATEST), 'deadline DESC') !== false);
t_equals('no sort leaves the board alone', deadline_sort_sql(''), '');
t_equals('an unknown sort leaves the board alone', deadline_sort_sql('id_asc'), '');

// --- what may be stored --------------------------------------------------
t_equals('a real date is kept', deadline_clean_input('2026-11-30'), '2026-11-30');
t_equals('blank clears the deadline', deadline_clean_input(''), '');
t_equals('surrounding space is trimmed', deadline_clean_input('  2026-11-30  '), '2026-11-30');
t_equals('a past date is allowed, because back-filling is real', deadline_clean_input('2020-01-01'), '2020-01-01');
t_equals('the 29th of a leap February is a date', deadline_clean_input('2028-02-29'), '2028-02-29');
t_equals('the 29th of a common February is not', deadline_clean_input('2027-02-29'), null);
t_equals('the 31st of November is not', deadline_clean_input('2026-11-31'), null);
t_equals('a 13th month is not', deadline_clean_input('2026-13-01'), null);
t_equals('the other way round is refused', deadline_clean_input('30/11/2026'), null);
t_equals('a word is refused', deadline_clean_input('next Friday'), null);
t_equals('a datetime is refused rather than silently cut', deadline_clean_input('2026-11-30 14:00:00'), null);
t_equals('an injection attempt is refused', deadline_clean_input("2026-11-30' OR '1"), null);

t_summary();
