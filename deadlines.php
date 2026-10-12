<?php

require_once __DIR__ . "/env_helper.php";

/**
 * What a deadline on a recommendation means, and how to read one.
 *
 * A deadline means "submit by this date". Everything here answers one of two
 * questions -- is this deadline still outstanding, and how urgent is it -- and
 * both the board and the sweep that sends the notices read the answers from
 * here, so there is one definition of "overdue" rather than two that drift.
 */

/**
 * Statuses that satisfy a deadline. Everything else leaves it outstanding,
 * including an office's own custom statuses: somebody inventing "In Progress"
 * has not submitted anything, and treating an unrecognised status as finished
 * would silence the alert, which is the one direction this must not fail in.
 */
const DEADLINE_CLOSED_STATUSES = ['Submitted', 'Approved', 'Completed'];

function deadline_is_open($status){
    return !in_array(trim((string) $status), DEADLINE_CLOSED_STATUSES, true);
}

/** The board's urgency buckets. Fixed windows, not the warning window. */
const DEADLINE_FILTER_OVERDUE = 'overdue';
const DEADLINE_FILTER_WEEK    = 'week';
const DEADLINE_FILTER_MONTH   = 'month';
const DEADLINE_FILTER_LATER   = 'later';
const DEADLINE_FILTER_NONE    = 'none';

/** Bucket value => the label the dropdown shows. */
function deadline_filter_options(){
    return [
        DEADLINE_FILTER_OVERDUE => 'Overdue',
        DEADLINE_FILTER_WEEK    => 'Due within 7 days',
        DEADLINE_FILTER_MONTH   => 'Due within 30 days',
        DEADLINE_FILTER_LATER   => 'Due later than 30 days',
        DEADLINE_FILTER_NONE    => 'No deadline set',
    ];
}

/**
 * How many days before the date counts as "due soon". Drives the amber cell
 * here and, in the sweep, when the warning goes out -- deliberately separate
 * from the 7- and 30-day buckets, which are for reading the board. Tying them
 * together would mean changing when people are emailed to change how the board
 * reads.
 */
function deadline_warn_days(){
    $days = (int) env_get('DEADLINE_WARN_DAYS', 3);
    return $days > 0 ? $days : 3;
}

/**
 * How to read one row's deadline: 'none', 'met', 'overdue', 'soon' or 'future'.
 *
 * A finished recommendation is never overdue however old its date, so this
 * answers from the status as well as the date. $today is injectable because
 * every boundary in this feature is a day apart and a test cannot wait a day.
 */
function deadline_state(array $row, $today = null){
    $deadline = trim((string) ($row['deadline'] ?? ''));
    if($deadline === '' || $deadline === '0000-00-00'){
        return 'none';
    }
    if(!deadline_is_open($row['status'] ?? '')){
        return 'met';
    }

    $today = $today !== null ? $today : date('Y-m-d');
    if($deadline < $today){
        return 'overdue';
    }

    $warnFrom = date('Y-m-d', strtotime($today . ' +' . deadline_warn_days() . ' days'));
    return $deadline <= $warnFrom ? 'soon' : 'future';
}

/**
 * The WHERE fragment for one bucket, or '' for no narrowing.
 *
 * Returns SQL with no placeholders because it interpolates nothing: the bucket
 * is matched against the known set and every value in the fragment is a
 * literal or CURDATE(). An unknown bucket narrows nothing rather than erroring,
 * so a stale bookmark shows the whole board instead of a broken page.
 *
 * The date buckets also require the recommendation to be outstanding. A
 * Completed one with a date last March is not what anybody means by "overdue",
 * and listing it would make the filter useless for chasing work.
 */
function deadline_filter_sql($bucket){
    $closed = "'" . implode("','", DEADLINE_CLOSED_STATUSES) . "'";
    $open   = "deadline IS NOT NULL AND status NOT IN ({$closed})";

    switch(trim((string) $bucket)){
        case DEADLINE_FILTER_OVERDUE:
            return "({$open} AND deadline < CURDATE())";
        case DEADLINE_FILTER_WEEK:
            return "({$open} AND deadline >= CURDATE() AND deadline <= CURDATE() + INTERVAL 7 DAY)";
        case DEADLINE_FILTER_MONTH:
            return "({$open} AND deadline >= CURDATE() AND deadline <= CURDATE() + INTERVAL 30 DAY)";
        case DEADLINE_FILTER_LATER:
            return "({$open} AND deadline > CURDATE() + INTERVAL 30 DAY)";
        case DEADLINE_FILTER_NONE:
            return "(deadline IS NULL)";
        default:
            return '';
    }
}

/** The sort values the Deadline column header can ask for. */
const DEADLINE_SORT_SOONEST = 'deadline_asc';
const DEADLINE_SORT_LATEST  = 'deadline_desc';

/**
 * The ORDER BY for a deadline sort, or '' to keep the board's own order.
 *
 * `deadline IS NULL` leads in both directions so undated rows always come
 * last: most recommendations have no date yet, and latest-first would
 * otherwise open on a wall of blanks.
 */
function deadline_sort_sql($sort){
    switch(trim((string) $sort)){
        case DEADLINE_SORT_SOONEST:
            return "deadline IS NULL, deadline ASC";
        case DEADLINE_SORT_LATEST:
            return "deadline IS NULL, deadline DESC";
        default:
            return '';
    }
}

/** Blank, or a date in YYYY-MM-DD. Anything else is refused rather than stored. */
function deadline_clean_input($value){
    $value = trim((string) $value);
    if($value === ''){
        return '';
    }
    $parts = date_parse_from_format('Y-m-d', $value);
    if($parts['error_count'] > 0 || $parts['warning_count'] > 0){
        return null;
    }
    return checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']) ? $value : null;
}
