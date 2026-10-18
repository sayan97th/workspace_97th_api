<?php

namespace App\Concerns;

use App\Models\BoardColumn;
use App\Models\BoardTag;
use App\Models\User;
use App\Services\Board\BoardItemImportService;
use App\Services\Board\ImportedCellValueCaster;
use App\Services\Board\MondayBoardImportService;
use App\Support\CountryCatalog;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guesses a {@see BoardColumn} data type (and, for option-based types, the
 * option list) from a column's label and its raw values — shared by the
 * `board:import-monday*` artisan commands ({@see MondayBoardImportService})
 * and the "Import items" wizard ({@see BoardItemImportService}), so a column
 * gets the same type whichever way a file is imported. The matching
 * write-side, turning a raw cell into the value shape each type stores, is
 * {@see ImportedCellValueCaster}, which reuses this trait's parsing helpers.
 *
 * Every guess is value-first: a column's header only *suggests* a type (a
 * monday.com board is free to name a Status column "Owner"), and the values
 * underneath it have to agree before that suggestion wins. A header alone
 * only decides the type of a column with no values at all, where there's
 * nothing else to go on. Whenever the evidence is weak the guess falls back
 * to {@see BoardColumn::TYPE_TEXT}/`TYPE_LONG_TEXT`, so an unrecognized
 * column still imports every value verbatim instead of losing data.
 */
trait InfersBoardColumnTypes
{
    /** @var array<int, string> */
    private const OPTION_COLOR_PALETTE = [
        '#00c875', '#579bfc', '#a25ddc', '#fdab3d', '#e2445c',
        '#66ccff', '#ff642e', '#7f5347', '#bb3354', '#0086c0', '#9d99b9',
    ];

    /** Familiar monday.com status/priority labels (matched case-insensitively) get their usual color instead of a palette-cycled one. */
    private const KNOWN_OPTION_COLORS = [
        'done' => '#00c875',
        'working on it' => '#fdab3d',
        'stuck' => '#e2445c',
        'on hold' => '#797e93',
        'hold' => '#797e93',
        'on track' => '#00c875',
        'ready for dev' => '#579bfc',
        'ready to start' => '#579bfc',
        'waiting for deployment' => '#a25ddc',
        'outlining' => '#9d99b9',
        'backlog' => '#c4c4c4',
        'resources' => '#66ccff',
        'in progress' => '#fdab3d',
        'progressing' => '#fdab3d',
        'stalled' => '#e2445c',
        "haven't started" => '#c4c4c4',
        'not started' => '#c4c4c4',
        'critical' => '#bb3354',
        'high' => '#e2445c',
        'medium' => '#fdab3d',
        'low' => '#579bfc',
    ];

    /**
     * Header labels (case-insensitive, exact) that suggest "this column names
     * a staff member". Only a suggestion — see {@see detectPeopleColumn()}.
     *
     * @var array<int, string>
     */
    private const PEOPLE_LABELS = [
        'owner', 'owners', 'assignee', 'assignees', 'assigned to', 'person', 'people', 'reporter', 'developer',
        'interviewer', 'designer', 'epic owner', 'creative owner', 'expert', 'members', 'team members',
    ];

    /**
     * Header labels (case-insensitive, exact) that name a free-form text
     * column, so a handful of repeated values under them never gets promoted
     * to a Status column.
     *
     * @var array<int, string>
     */
    private const TEXT_LABELS = [
        'text', 'notes', 'note', 'comments', 'comment', 'details', 'summary', 'description', 'remarks',
    ];

    /** How monday.com's export writes a ticked Checkbox cell (a lone "v"), plus the usual spreadsheet spellings of "yes". */
    private const CHECKED_TOKENS = ['v', '✓', '✔', '☑', 'x', 'yes', 'y', 'true', 'checked'];

    /** Cell values that mean "unticked" rather than "has a value" for a Checkbox column. */
    private const UNCHECKED_TOKENS = ['false', 'no', 'n', '0', 'unchecked', '-'];

    /** A real tag/label token reads as a short word or phrase; prose split on a comma produces much longer fragments. */
    private const MAX_TAG_TOKEN_LENGTH = 40;

    /** Share of a column's values that must agree before a value-based guess wins. */
    private const DETECTION_THRESHOLD = 0.9;

    /**
     * A monday.com "Last updated" / "Creation log" cell: who, then when, e.g.
     * "Catherine Tandy Aug 30, 2026 11:01 AM". Only the timestamp is kept, since
     * a Date column has nowhere to store the person.
     */
    private const ACTIVITY_LOG_PATTERN = '/^(?<person>\p{L}[\p{L}\'.\-]*(?:\s+\p{L}[\p{L}\'.\-]*){0,3})\s+(?<date>(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},\s+\d{4}(?:\s+\d{1,2}:\d{2}\s*[AP]M)?)$/u';

    /** A checklist line: an optional bullet, then a "[x]" / "[ ]" / "☑" / "☐" style marker, then the sub-task's text. */
    private const CHECKLIST_LINE_PATTERN = '/^\s*(?:[-*•]\s*)?(?<marker>\[(?:\s|x|X|✓|✔)?\]|☐|☑|✅|✓|✔)\s*(?<text>.+)$/u';

    /** A Time Tracking cell as monday.com exports it: "HH:MM:SS". */
    private const DURATION_PATTERN = '/^(\d+):([0-5]\d):([0-5]\d)$/';

    /** An Hour cell: "14:30", "2:30 PM", "02:30pm" or "9 AM". Seconds are never part of it, that's a duration. */
    private const HOUR_PATTERN = '/^(?<hour>\d{1,2})(?::(?<minute>[0-5]\d))?\s*(?<meridiem>[AaPp]\.?\s?[Mm]\.?)?$/';

    /** A week written the ISO way ("2026-W38", "2026W38") or spelled out ("W38 2026", "Week 38, 2026"). */
    private const ISO_WEEK_PATTERNS = [
        '/^(?<year>\d{4})-?W(?<week>\d{1,2})$/i',
        '/^W(?:eek)?\s*(?<week>\d{1,2}),?\s+(?<year>\d{4})$/i',
    ];

    /** Headers monday.com gives its own Item ID column, the item's id rather than data anyone typed. */
    private const ITEM_ID_LABELS = ['item id', 'item id (auto generated)', 'pulse id'];

    /**
     * Date (and date-time) layouts monday.com and common spreadsheets export,
     * tried strictly and in order. Deliberately an explicit list rather than
     * `Carbon::parse()`, which happily reads "5", "Done" or "Sam May 8, 2026"
     * as *some* date and would turn a number/status column into dates.
     *
     * @var array<int, string>
     */
    private const DATE_FORMATS = [
        'Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s',
        'n/j/Y', 'n/j/Y H:i', 'n/j/Y g:i A', 'n/j/y',
        'M j, Y', 'M j, Y g:i A', 'F j, Y', 'F j, Y g:i A', 'j M Y', 'd/F/Y h:i:s A',
    ];

    /**
     * @param  array<int, string>  $raw_values
     * @param  Collection<int, User>|null  $users  existing users a People column's names are matched against; null when unavailable
     * @return array{0: string, 1: array<int, string>, 2: string} `[type, option labels, human-readable reason for the guess]`
     */
    private function inferColumnType(string $label, array $raw_values, ?Collection $users = null): array
    {
        $values = array_values(array_filter(array_map('trim', $raw_values), fn (string $value) => $value !== ''));
        $label_lower = mb_strtolower(trim($label));
        $hint = $this->labelTypeHint($label_lower);
        $count = count($values);

        if ($count === 0) {
            return $hint !== null
                ? [$hint, [], "No values yet, typed from its \"{$label}\" header"]
                : [BoardColumn::TYPE_TEXT, [], 'No values to detect a type from'];
        }

        $distinct = array_values(array_unique($values));

        if ($hint === BoardColumn::TYPE_CHECKBOX || $this->shareMatching($values, fn (string $value) => $this->isCheckedToken($value)) === 1.0) {
            return [BoardColumn::TYPE_CHECKBOX, [], $hint === BoardColumn::TYPE_CHECKBOX ? 'Header names a checkbox' : "All {$count} values are checkmarks"];
        }

        $checklist_share = $this->shareMatching($values, fn (string $value) => $this->isChecklistCell($value));
        if ($checklist_share >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_CHECKLIST, [], 'Values are checklists of done/not done sub-tasks'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseHexColor($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_COLOR_PICKER, [], 'Values are hex colors'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseNumber($value) !== null) === 1.0) {
            return $this->detectNumericColumn($values, $hint, $label_lower);
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseDuration($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_TIME_TRACKING, [], 'Values are tracked durations (hh:mm:ss)'];
        }

        if ($hint !== BoardColumn::TYPE_TIME_TRACKING && $this->shareMatching($values, fn (string $value) => $this->parseHour($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_HOUR, [], 'Values are times of day'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseTimeZone($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_WORLD_CLOCK, [], 'Values are time zones'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseIsoWeek($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_WEEK, [], 'Values are week numbers'];
        }

        if ($hint === BoardColumn::TYPE_WEEK && $this->shareMatching($values, fn (string $value) => $this->parseWeek($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_WEEK, [], 'Header names a week and the values read as weeks'];
        }

        if ($hint === BoardColumn::TYPE_PROGRESS && $this->shareMatching($values, fn (string $value) => $this->parsePercent($value) !== null) === 1.0) {
            return [BoardColumn::TYPE_PROGRESS, [], 'Every value is a percentage'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->isSingleEmail($value)) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_EMAIL, [], 'Values are email addresses'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->extractEmails($value) !== []) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_LONG_TEXT, [], 'Cells hold several email addresses each, kept as text so none are lost'];
        }

        if ($hint === BoardColumn::TYPE_PHONE && $this->shareMatching($values, fn (string $value) => $this->isPhone($value)) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_PHONE, [], 'Values are phone numbers'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseDateRange($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_TIMELINE, [], 'Values are date ranges'];
        }

        $date_share = $this->shareMatching($values, fn (string $value) => $this->parseDate($value) !== null);
        if ($date_share >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_DATE, [], sprintf('%d%% of values are dates', (int) round($date_share * 100))];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseActivityLogDate($value) !== null) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_DATE, [], 'Values are "person + timestamp" activity entries, kept as the timestamp'];
        }

        if ($hint === BoardColumn::TYPE_CHECKLIST) {
            return [BoardColumn::TYPE_CHECKLIST, [], 'Header names a checklist, each line or comma-separated entry becomes a sub-task'];
        }

        if ($this->shareMatching($values, fn (string $value) => $this->parseLinks($value) !== null) >= 0.8) {
            return $this->detectLinkColumn($values, $hint);
        }

        if ($hint === BoardColumn::TYPE_DEPENDENCY) {
            return [BoardColumn::TYPE_DEPENDENCY, [], 'Header names a dependency, item names are linked once every row is imported'];
        }

        if ($hint === BoardColumn::TYPE_LOCATION && $this->isSingleLineText($values, 500)) {
            return [BoardColumn::TYPE_LOCATION, [], 'Header names a location, each value is kept as an address'];
        }

        $people = $this->detectPeopleColumn($values, $hint, $users);
        if ($people !== null) {
            return $people;
        }

        $country = $this->detectCountryColumn($values, $distinct, $hint);
        if ($country !== null) {
            return $country;
        }

        if ($hint === BoardColumn::TYPE_TEXT) {
            return $this->detectTextColumn($values, 'Header names free-form text');
        }

        // A cell repeating the same token ("Done, Done, Stuck") is a mirror or
        // rollup of linked items' values, not a set of picked options.
        if ($this->shareMatching($values, fn (string $value) => $this->hasRepeatedTokens($value)) > 0) {
            return $this->detectTextColumn($values, 'Cells repeat values from linked items (a mirror or rollup), kept as text');
        }

        if ($hint === BoardColumn::TYPE_LABEL) {
            return [BoardColumn::TYPE_LABEL, $distinct, 'Header names a priority'];
        }

        if ($hint === BoardColumn::TYPE_STATUS && count($distinct) <= 50 && $this->maxLength($distinct) <= 60) {
            return [BoardColumn::TYPE_STATUS, $distinct, 'Header names a status'];
        }

        $multi_select = $this->detectMultiSelectColumn($values, $hint, $label_lower);
        if ($multi_select !== null) {
            return $multi_select;
        }

        if ($hint === BoardColumn::TYPE_DROPDOWN && count($distinct) <= 60 && $this->maxLength($distinct) <= self::MAX_TAG_TOKEN_LENGTH) {
            return [BoardColumn::TYPE_DROPDOWN, $distinct, 'Header names a dropdown'];
        }

        if (count($distinct) <= 12 && $count > count($distinct) && $this->maxLength($distinct) <= 40 && ! str_contains($label_lower, 'name')) {
            return [BoardColumn::TYPE_STATUS, $distinct, sprintf('%d values repeat across %d rows', count($distinct), $count)];
        }

        return $this->detectTextColumn($values);
    }

    /**
     * Text or Long text, depending on how long the values run.
     *
     * @param  array<int, string>  $values
     * @return array{0: string, 1: array<int, string>, 2: string}
     */
    private function detectTextColumn(array $values, ?string $reason = null): array
    {
        if ($this->maxLength($values) > 150 || $this->averageLength($values) > 80 || $this->shareMatching($values, fn (string $value) => str_contains($value, "\n")) > 0) {
            return [BoardColumn::TYPE_LONG_TEXT, [], $reason ?? 'Values are long or span several lines'];
        }

        return [BoardColumn::TYPE_TEXT, [], $reason ?? 'Free-form text'];
    }

    /**
     * Every column type that can store (nearly) all of these values without
     * losing them, in {@see BoardColumn} type order: the wizard's "Map
     * columns" step recommends these and warns when a column is pointed at a
     * type outside the list. With no values at all, every type fits.
     *
     * @param  array<int, string>  $candidate_types
     * @param  array<int, string>  $raw_values
     * @param  Collection<int, User>|null  $users
     * @return array<int, string>
     */
    private function compatibleColumnTypes(array $candidate_types, array $raw_values, ?Collection $users = null): array
    {
        $values = array_values(array_filter(array_map('trim', $raw_values), fn (string $value) => $value !== ''));

        if ($values === []) {
            return array_values($candidate_types);
        }

        $fits = fn (callable $matches) => $this->shareMatching($values, $matches) >= self::DETECTION_THRESHOLD;
        $max_length = $this->maxLength($values);

        $compatible = array_filter($candidate_types, fn (string $type) => match ($type) {
            BoardColumn::TYPE_TEXT => $max_length <= 2000 && $this->shareMatching($values, fn (string $value) => str_contains($value, "\n")) === 0.0,
            BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_CHECKLIST, BoardColumn::TYPE_DEPENDENCY => true,
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $max_length <= 255 && count(array_unique($values)) <= 60,
            BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_TAGS => $fits(fn (string $value) => $this->maxLength($this->splitList($value)) <= 255),
            BoardColumn::TYPE_NUMBER => $fits(fn (string $value) => $this->parseNumber($value) !== null),
            BoardColumn::TYPE_PROGRESS => $fits(fn (string $value) => $this->parsePercent($value) !== null),
            BoardColumn::TYPE_RATING => $fits(fn (string $value) => ($number = $this->parseNumber($value)) !== null && $number >= 0 && $number <= BoardColumn::RATING_MAX_LIMIT),
            BoardColumn::TYPE_CHECKBOX => $fits(fn (string $value) => $this->isCheckedToken($value) || $this->isUncheckedToken($value)),
            BoardColumn::TYPE_DATE => $fits(fn (string $value) => $this->parseDate($value) !== null || $this->parseActivityLogDate($value) !== null),
            BoardColumn::TYPE_TIMELINE => $fits(fn (string $value) => $this->parseDateRange($value) !== null || $this->parseDate($value) !== null),
            BoardColumn::TYPE_EMAIL => $fits(fn (string $value) => $this->extractEmails($value) !== []),
            BoardColumn::TYPE_PHONE => $fits(fn (string $value) => $this->isPhone($value)),
            BoardColumn::TYPE_LINK, BoardColumn::TYPE_FILES => $fits(fn (string $value) => $this->parseLinks($value) !== null),
            BoardColumn::TYPE_TIME_TRACKING => $fits(fn (string $value) => $this->parseDuration($value) !== null || $this->parseNumber($value) !== null),
            BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE => $users !== null && $this->detectPeopleColumn($values, BoardColumn::TYPE_PEOPLE, $users) !== null,
            BoardColumn::TYPE_COUNTRY => $fits(fn (string $value) => CountryCatalog::codeFor($value) !== null),
            BoardColumn::TYPE_WORLD_CLOCK => $fits(fn (string $value) => $this->parseTimeZone($value) !== null),
            BoardColumn::TYPE_WEEK => $fits(fn (string $value) => $this->parseWeek($value) !== null),
            BoardColumn::TYPE_HOUR => $fits(fn (string $value) => $this->parseHour($value) !== null),
            BoardColumn::TYPE_COLOR_PICKER => $fits(fn (string $value) => $this->parseHexColor($value) !== null),
            BoardColumn::TYPE_LOCATION => $this->isSingleLineText($values, 500),
            default => false,
        });

        return array_values($compatible);
    }

    /**
     * A column's header keyword mapped to the type monday.com gives a column
     * of that name by default — the "Status", "Date", "Files", ... columns
     * every new monday.com board starts with.
     */
    private function labelTypeHint(string $label_lower): ?string
    {
        if (in_array($label_lower, self::PEOPLE_LABELS, true)) {
            return BoardColumn::TYPE_PEOPLE;
        }

        if (in_array($label_lower, self::TEXT_LABELS, true)) {
            return BoardColumn::TYPE_TEXT;
        }

        $keyword_hints = [
            BoardColumn::TYPE_WORLD_CLOCK => ['world clock', 'time zone', 'timezone'],
            BoardColumn::TYPE_CHECKBOX => ['checkbox'],
            BoardColumn::TYPE_CHECKLIST => ['checklist', 'to do', 'to-do', 'todo', 'todos', 'subtasks', 'sub tasks', 'sub-tasks', 'action items'],
            BoardColumn::TYPE_DEPENDENCY => ['dependency', 'dependencies', 'depends on', 'dependent on', 'blocked by', 'predecessor', 'predecessors'],
            BoardColumn::TYPE_RATING => ['rating'],
            BoardColumn::TYPE_PROGRESS => ['progress', 'percent', 'completion %'],
            BoardColumn::TYPE_VOTE => ['vote'],
            BoardColumn::TYPE_TIME_TRACKING => ['time tracking', 'duration'],
            BoardColumn::TYPE_EMAIL => ['email', 'e-mail'],
            BoardColumn::TYPE_PHONE => ['phone', 'mobile'],
            BoardColumn::TYPE_FILES => ['files', 'file', 'attachment'],
            BoardColumn::TYPE_LINK => ['link', 'url', 'website'],
            BoardColumn::TYPE_DROPDOWN => ['dropdown'],
            BoardColumn::TYPE_TAGS => ['tags', 'tag'],
            BoardColumn::TYPE_LABEL => ['priority'],
            BoardColumn::TYPE_STATUS => ['status', 'stage'],
            BoardColumn::TYPE_TIMELINE => ['timeline'],
            BoardColumn::TYPE_COUNTRY => ['country', 'countries'],
            BoardColumn::TYPE_LOCATION => ['location', 'address'],
            BoardColumn::TYPE_WEEK => ['week'],
            BoardColumn::TYPE_HOUR => ['hour', 'time of day', 'start time', 'end time'],
            BoardColumn::TYPE_COLOR_PICKER => ['color', 'colour'],
            BoardColumn::TYPE_NUMBER => ['hours', 'estimate', 'points', 'budget', 'cost', 'price', 'amount', 'quantity'],
        ];

        foreach ($keyword_hints as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $label_lower) === 1) {
                    return $type;
                }
            }
        }

        if (preg_match('/\b(date|deadline|due)\b/u', $label_lower) === 1 && ! str_contains($label_lower, 'update')) {
            return BoardColumn::TYPE_DATE;
        }

        return null;
    }

    /**
     * @param  non-empty-array<int, string>  $values  every value already parses as a number
     * @return array{0: string, 1: array<int, string>, 2: string}
     */
    private function detectNumericColumn(array $values, ?string $hint, string $label_lower): array
    {
        $numbers = array_map(fn (string $value) => (float) $this->parseNumber($value), $values);
        $all_whole = array_filter($numbers, fn (float $number) => floor($number) !== $number) === [];

        // monday.com item/pulse ids and similar references read as numbers but aren't quantities
        // anyone sums; a float would also silently lose their last digits.
        if ($all_whole && $this->shareMatching($values, fn (string $value) => preg_match('/^\d{9,}$/', $value) === 1) === 1.0) {
            // monday.com's own Item ID column becomes this board's Item ID column, numbered with the new items' ids.
            if (in_array($label_lower, self::ITEM_ID_LABELS, true)) {
                return [BoardColumn::TYPE_AUTO_NUMBER, [], 'monday.com item ids, every imported item gets its own Item ID'];
            }

            return [BoardColumn::TYPE_TEXT, [], 'Values are long identifiers, kept as text'];
        }

        if ($hint === BoardColumn::TYPE_RATING && $all_whole && min($numbers) >= 0 && max($numbers) <= BoardColumn::RATING_MAX_LIMIT) {
            return [BoardColumn::TYPE_RATING, [], sprintf('Whole numbers from 0 to %d under a rating header', max(BoardColumn::RATING_DEFAULT_MAX, (int) max($numbers)))];
        }

        if ($hint === BoardColumn::TYPE_PROGRESS && min($numbers) >= 0 && max($numbers) <= 100) {
            return [BoardColumn::TYPE_PROGRESS, [], 'Numbers from 0 to 100 under a progress header'];
        }

        if (str_contains($label_lower, 'checkbox')) {
            return [BoardColumn::TYPE_CHECKBOX, [], 'Header names a checkbox'];
        }

        return [BoardColumn::TYPE_NUMBER, [], 'Every value is a number'];
    }

    /**
     * monday.com exports a Link cell as "Display text - https://..." and a
     * Files cell as one or more bare asset URLs, so a URL-shaped column is a
     * Files column when its header says so, when any cell holds more than one
     * URL, or when it points at monday.com's own asset storage — and a Link
     * column otherwise.
     *
     * @param  array<int, string>  $values
     * @return array{0: string, 1: array<int, string>, 2: string}
     */
    private function detectLinkColumn(array $values, ?string $hint): array
    {
        $has_multiple = $this->shareMatching($values, fn (string $value) => count($this->parseLinks($value) ?? []) > 1) > 0;
        $has_monday_assets = $this->shareMatching($values, fn (string $value) => str_contains($value, 'monday.com/protected_static')) > 0;

        if ($hint === BoardColumn::TYPE_FILES || $has_multiple || $has_monday_assets) {
            return [BoardColumn::TYPE_FILES, [], $has_multiple ? 'Cells hold one or more links each' : 'Values are file links'];
        }

        return [BoardColumn::TYPE_LINK, [], 'Values are links'];
    }

    /**
     * A People column needs its values to *look* like people, not just a
     * people-sounding header: most names must match an existing user, or
     * (when the header already says "Owner"/"Assignee"/...) most values must
     * at least read as a person's name, so a Status column somebody named
     * "Owner" (holding "Done", "SEO", ...) still imports as a Status column.
     * A header-less column only becomes People on a strong user match, so a
     * column of client names never swallows its data into unmatched people.
     *
     * @param  array<int, string>  $values
     * @param  Collection<int, User>|null  $users
     * @return array{0: string, 1: array<int, string>, 2: string}|null
     */
    private function detectPeopleColumn(array $values, ?string $hint, ?Collection $users): ?array
    {
        $names = [];
        foreach ($values as $value) {
            foreach ($this->splitList($value) as $name) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);

        if ($names === []) {
            return null;
        }

        $matched = $users === null ? 0 : count(array_filter($names, fn (string $name) => $this->matchUser($name, $users) !== null));
        $match_share = $matched / count($names);

        if ($hint === BoardColumn::TYPE_PEOPLE) {
            if ($match_share >= 0.5) {
                return [BoardColumn::TYPE_PEOPLE, [], sprintf('%d of %d names match existing users', $matched, count($names))];
            }

            if ($this->shareMatching($names, fn (string $name) => $this->looksLikePersonName($name)) >= 0.7) {
                return [BoardColumn::TYPE_PEOPLE, [], 'Header names a person and the values read as people\'s names'];
            }

            return null;
        }

        if ($matched >= 2 && $match_share >= 0.6) {
            return [BoardColumn::TYPE_PEOPLE, [], sprintf('%d of %d names match existing users', $matched, count($names))];
        }

        return null;
    }

    /**
     * A comma-separated list of short, reused tokens ("SEO, Content",
     * "Retainer, Hourly") is monday.com's Dropdown export — a genuine
     * multi-select, where each token is its own option rather than every
     * distinct combination becoming a status label of its own. A Tags column
     * is only picked when the header asks for it, since Tags options are
     * board-wide rather than owned by the column. A column whose header
     * names a "name" is never split, since "Doe, Jane" is one value.
     *
     * @param  array<int, string>  $values
     * @return array{0: string, 1: array<int, string>, 2: string}|null
     */
    private function detectMultiSelectColumn(array $values, ?string $hint, string $label_lower): ?array
    {
        $with_comma = array_values(array_filter($values, fn (string $value) => str_contains($value, ',')));

        if ($with_comma === [] || str_contains($label_lower, 'name')) {
            return null;
        }

        // A cell repeating the same token ("Done, Done, Stuck") is a rollup/
        // mirror of other items' values, not a set of picked options — keeping
        // it as text is the only way to keep those counts.
        if ($this->shareMatching($with_comma, fn (string $value) => $this->hasRepeatedTokens($value)) > 0) {
            return null;
        }

        $tokens = [];
        foreach ($values as $value) {
            foreach ($this->splitList($value) as $token) {
                $tokens[$token] = true;
            }
        }
        $token_labels = array_keys($tokens);

        // A genuine tag list splits into short, word-or-phrase-like tokens
        // ("backend", "urgent"); free-text prose that merely contains a
        // comma splits into much longer sentence fragments — that shape
        // difference is what tells the two apart, not the raw token count.
        if (count($token_labels) > 60 || $this->maxLength($token_labels) > self::MAX_TAG_TOKEN_LENGTH) {
            return null;
        }

        $standalone = array_flip(array_filter($values, fn (string $value) => ! str_contains($value, ',')));
        $reuses_standalone_tokens = false;
        foreach ($with_comma as $value) {
            foreach ($this->splitList($value) as $token) {
                if (isset($standalone[$token])) {
                    $reuses_standalone_tokens = true;
                    break 2;
                }
            }
        }

        if (count($with_comma) / count($values) < 0.3 && ! $reuses_standalone_tokens) {
            return null;
        }

        $type = $hint === BoardColumn::TYPE_TAGS ? BoardColumn::TYPE_TAGS : BoardColumn::TYPE_DROPDOWN;

        return [$type, $token_labels, sprintf('Comma-separated choices from %d options', count($token_labels))];
    }

    /**
     * A Country column: the values name countries, and either the header says
     * so or the values spell out full country names. Bare two letter codes
     * without a "Country" header are left alone, since "CA" or "GA" could just
     * as well be US states.
     *
     * @param  array<int, string>  $values
     * @param  array<int, string>  $distinct
     * @return array{0: string, 1: array<int, string>, 2: string}|null
     */
    private function detectCountryColumn(array $values, array $distinct, ?string $hint): ?array
    {
        if ($this->shareMatching($values, fn (string $value) => CountryCatalog::codeFor($value) !== null) < self::DETECTION_THRESHOLD) {
            return null;
        }

        if ($hint === BoardColumn::TYPE_COUNTRY) {
            return [BoardColumn::TYPE_COUNTRY, [], 'Header names a country and the values are countries'];
        }

        if (count($distinct) >= 2 && $this->shareMatching($values, fn (string $value) => CountryCatalog::isCountryName($value)) >= self::DETECTION_THRESHOLD) {
            return [BoardColumn::TYPE_COUNTRY, [], 'Values are country names'];
        }

        return null;
    }

    /**
     * True when every value fits on one line and runs no longer than `$max_length`.
     *
     * @param  array<int, string>  $values
     */
    private function isSingleLineText(array $values, int $max_length): bool
    {
        return $this->maxLength($values) <= $max_length
            && $this->shareMatching($values, fn (string $value) => str_contains($value, "\n")) === 0.0;
    }

    /** A `#rrggbb` color from "#00C875", "#0c8" or "00c875" under no other reading; null for anything else. */
    private function parseHexColor(string $raw): ?string
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($raw), $matches) !== 1) {
            return null;
        }

        $hex = strtolower($matches[1]);

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /**
     * An Hour cell's value, `H:i` on a 24 hour clock ("2:30 PM" is "14:30"), and
     * whether it was written on a 12 hour clock. Null for anything else.
     *
     * @return array{value: string, is_12_hour: bool}|null
     */
    private function parseHour(string $raw): ?array
    {
        $raw = trim($raw);

        if (preg_match(self::HOUR_PATTERN, $raw, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches['hour'];
        $minute = isset($matches['minute']) && $matches['minute'] !== '' ? (int) $matches['minute'] : 0;
        $meridiem = strtolower(str_replace(['.', ' '], '', $matches['meridiem'] ?? ''));

        // A bare number ("9") is a number, not an hour.
        if ($meridiem === '' && ($matches['minute'] ?? '') === '') {
            return null;
        }

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $hour = ($hour % 12) + ($meridiem === 'pm' ? 12 : 0);
        } elseif ($hour > 23) {
            return null;
        }

        return ['value' => sprintf('%02d:%02d', $hour, $minute), 'is_12_hour' => $meridiem !== ''];
    }

    /**
     * The IANA time zone a World clock cell names, "America/New_York" alone or
     * inside a label like "(GMT-05:00) America/New_York". Legacy aliases such
     * as "US/Eastern" count too, since the cell's picker lists them.
     */
    private function parseTimeZone(string $raw): ?string
    {
        static $zones_by_lower = null;
        $zones_by_lower ??= collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))
            ->mapWithKeys(fn (string $zone) => [mb_strtolower($zone) => $zone])
            ->all();

        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) > 80) {
            return null;
        }

        if (isset($zones_by_lower[mb_strtolower($raw)])) {
            return $zones_by_lower[mb_strtolower($raw)];
        }

        if (preg_match('/\b([A-Za-z]+(?:\/[A-Za-z0-9_+\-]+){1,2})\b/', $raw, $matches) === 1) {
            return $zones_by_lower[mb_strtolower($matches[1])] ?? null;
        }

        return null;
    }

    /** The Monday (`Y-m-d`) of a week written the ISO way ("2026-W38") or spelled out ("Week 38, 2026"). */
    private function parseIsoWeek(string $raw): ?string
    {
        foreach (self::ISO_WEEK_PATTERNS as $pattern) {
            if (preg_match($pattern, trim($raw), $matches) !== 1) {
                continue;
            }

            $week = (int) $matches['week'];
            $year = (int) $matches['year'];

            if ($week < 1 || $week > 53 || $year < 1900 || $year > 2200) {
                return null;
            }

            return Carbon::now()->setISODate($year, $week)->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        return null;
    }

    /**
     * The Monday (`Y-m-d`) a Week cell stands for: an ISO week, a range of at
     * most seven days ("Sep 14, 2026 - Sep 20, 2026", how monday.com exports a
     * Week column), or any single day of that week.
     */
    private function parseWeek(string $raw): ?string
    {
        $iso_week = $this->parseIsoWeek($raw);
        if ($iso_week !== null) {
            return $iso_week;
        }

        $range = $this->parseDateRange($raw);
        if ($range !== null) {
            $start = Carbon::parse($range['start']);

            return abs($start->diffInDays(Carbon::parse($range['end']))) <= 6
                ? $start->startOfWeek(Carbon::MONDAY)->toDateString()
                : null;
        }

        $date = $this->parseDate($raw);

        return $date === null ? null : Carbon::parse(substr($date, 0, 10))->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /**
     * @param  array<int, string>  $values
     * @param  callable(string): bool  $matches
     */
    private function shareMatching(array $values, callable $matches): float
    {
        if ($values === []) {
            return 0.0;
        }

        return count(array_filter($values, $matches)) / count($values);
    }

    /** @param  array<int, string>  $values */
    private function maxLength(array $values): int
    {
        return array_reduce($values, fn (int $max, string $value) => max($max, mb_strlen($value)), 0);
    }

    /** @param  array<int, string>  $values */
    private function averageLength(array $values): float
    {
        return $values === [] ? 0.0 : array_sum(array_map('mb_strlen', $values)) / count($values);
    }

    private function isCheckedToken(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::CHECKED_TOKENS, true);
    }

    private function isUncheckedToken(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::UNCHECKED_TOKENS, true);
    }

    /** Parses "1,500", "$20", "12.5" or "-3" into a number; null for anything else. */
    private function parseNumber(string $raw): ?float
    {
        $value = trim($raw);

        if (preg_match('/^[$€£]?\s*-?\d{1,3}(,\d{3})+(\.\d+)?$/u', $value) === 1) {
            $value = str_replace(',', '', $value);
        }

        $value = preg_replace('/^[$€£]\s*/u', '', $value) ?? $value;

        return is_numeric($value) ? (float) $value : null;
    }

    /** Parses "45%" or "45" into a 0-100 value; null when out of range or not a number. */
    private function parsePercent(string $raw): ?float
    {
        $number = $this->parseNumber(rtrim(trim($raw), '%'));

        return $number !== null && $number >= 0 && $number <= 100 ? $number : null;
    }

    private function isSingleEmail(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array<int, string> */
    private function extractEmails(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);

        return $matches[0];
    }

    private function isPhone(string $value): bool
    {
        return preg_match('/^\+?[0-9()\-.\s]{7,30}$/', trim($value)) === 1
            && preg_match_all('/\d/', $value) >= 7;
    }

    private function looksLikePersonName(string $value): bool
    {
        return preg_match('/^\p{Lu}[\p{L}\'.\-]+(\s+\p{Lu}[\p{L}\'.\-]+){1,3}$/u', trim($value)) === 1;
    }

    /**
     * Normalizes a date cell to `Y-m-d`, or `Y-m-d\TH:i` when it carries a
     * time of day (e.g. monday.com's "Creation log" column, "May 2, 2024 3:04
     * PM") — the same two shapes the Date cell itself stores.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) > 40) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $raw);
            } catch (Throwable) {
                continue;
            }

            if ($date === null || $date->year < 1900 || $date->year > 2200) {
                continue;
            }

            $errors = Carbon::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            $has_time = preg_match('/[HhGg]/', $format) === 1 && $date->format('H:i') !== '00:00';

            return $has_time ? $date->format('Y-m-d\TH:i') : $date->toDateString();
        }

        return null;
    }

    /**
     * The timestamp of a monday.com "Last updated" / "Creation log" cell
     * ("Sam May 8, 2026 4:43 PM"), normalized the same way {@see parseDate()}
     * normalizes a plain date. Null for anything else.
     */
    private function parseActivityLogDate(string $raw): ?string
    {
        if (preg_match(self::ACTIVITY_LOG_PATTERN, trim($raw), $matches) !== 1) {
            return null;
        }

        return $this->parseDate($matches['date']);
    }

    /** Reads a monday.com Time Tracking cell ("01:30:00") into seconds; null for anything else. */
    private function parseDuration(string $raw): ?int
    {
        if (preg_match(self::DURATION_PATTERN, trim($raw), $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    /** True when every line of the cell carries a checklist marker ("[x] Send recap", "☐ Book call"). */
    private function isChecklistCell(string $value): bool
    {
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []), fn (string $line) => $line !== '');

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (preg_match(self::CHECKLIST_LINE_PATTERN, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Splits a checklist cell into its sub-tasks. Each line is one entry when
     * the cell spans several lines; otherwise commas separate entries, except
     * inside parentheses, so "CRUD (Create, Read)" stays one sub-task. A
     * "[x]" / "☑" / "✓" marker ticks an entry; anything else starts unticked.
     *
     * @return array<int, array{text: string, is_done: bool}>
     */
    private function parseChecklistEntries(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $parts = preg_match('/\r\n|\r|\n/', $raw) === 1
            ? preg_split('/\r\n|\r|\n/', $raw) ?: [$raw]
            : $this->splitOutsideParentheses($raw);

        $entries = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match(self::CHECKLIST_LINE_PATTERN, $part, $matches) === 1) {
                $marker = mb_strtolower($matches['marker']);
                $is_done = ! in_array($marker, ['[]', '[ ]', '☐'], true);
                $entries[] = ['text' => trim($matches['text']), 'is_done' => $is_done];

                continue;
            }

            $entries[] = ['text' => ltrim($part, "-*• \t"), 'is_done' => false];
        }

        return array_values(array_filter($entries, fn (array $entry) => $entry['text'] !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function splitOutsideParentheses(string $raw): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (mb_str_split($raw) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }

    /** True for a comma-separated cell that repeats a token ("Done, Done, Stuck"). */
    private function hasRepeatedTokens(string $value): bool
    {
        if (! str_contains($value, ',')) {
            return false;
        }

        $tokens = array_map('mb_strtolower', $this->splitList($value));

        return count($tokens) !== count(array_unique($tokens));
    }

    /**
     * Parses a single-cell date range ("2024-01-01 - 2024-01-31"), the way a
     * monday.com Timeline column exports when it isn't split into its own
     * "- Start"/"- End" pair.
     *
     * @return array{start: string, end: string}|null
     */
    private function parseDateRange(string $raw): ?array
    {
        if (preg_match('/^(.+?)\s+(?:-|–|to)\s+(.+)$/u', trim($raw), $matches) !== 1) {
            return null;
        }

        $start = $this->parseDate($matches[1]);
        $end = $this->parseDate($matches[2]);

        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => substr($start, 0, 10), 'end' => substr($end, 0, 10)];
    }

    /**
     * Splits a Link/Files cell into its links. Accepts monday.com's own
     * "Display text - https://..." layout, a bare URL, or several of either
     * separated by commas. Returns null when the cell isn't made up of links
     * alone (a sentence that merely mentions a URL is prose, not a link).
     *
     * @return array<int, array{url: string, text: string}>|null
     */
    private function parseLinks(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '' || ! str_contains($raw, 'http')) {
            return null;
        }

        $entries = preg_split('/,\s*(?=(?:[^,]*?\s-\s)?https?:\/\/)/u', $raw) ?: [$raw];
        $links = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if (preg_match('/^(?:(.{1,200}?)\s+-\s+)?(https?:\/\/\S+)$/us', $entry, $matches) === 1) {
                $text = trim($matches[1]);
                $links[] = ['url' => $matches[2], 'text' => $text !== '' ? $text : $matches[2]];

                continue;
            }

            // monday.com's Files export leaves spaces in an asset's file name
            // ("https://.../resources/123/Mask Group-1.png"), so a bare URL
            // may contain spaces as long as it still ends in a file extension.
            if (preg_match('/^https?:\/\/\S+(?:\s\S+)*\.[A-Za-z0-9]{2,5}$/u', $entry) === 1) {
                $url = str_replace(' ', '%20', $entry);
                $links[] = ['url' => $url, 'text' => $url];

                continue;
            }

            return null;
        }

        return $links;
    }

    /**
     * Splits a comma-separated cell (people names, tag tokens) into trimmed,
     * non-empty tokens.
     *
     * @return array<int, string>
     */
    private function splitList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $value) => $value !== ''));
    }

    /**
     * Matches one name from a sheet against an existing user. A full match
     * requires both the user's first and last name to appear as whole words
     * in the raw name, so "Ernesto McIntosh Afane" matches a user named
     * "Ernesto Afane" even though monday.com's export includes a middle name
     * the app's user record doesn't have. A single-word name ("Jaecie")
     * matches only when exactly one user has that first name.
     *
     * @param  Collection<int, User>  $users
     */
    private function matchUser(string $name, Collection $users): ?User
    {
        $name_lower = mb_strtolower(trim($name));
        $haystack = ' '.$name_lower.' ';

        $full_match = $users->first(function (User $user) use ($haystack) {
            $first = mb_strtolower((string) $user->first_name);
            $last = mb_strtolower((string) $user->last_name);

            return $first !== '' && $last !== ''
                && str_contains($haystack, " {$first} ")
                && str_contains($haystack, " {$last} ");
        });

        if ($full_match !== null || str_contains($name_lower, ' ')) {
            return $full_match;
        }

        $first_name_matches = $users->filter(fn (User $user) => mb_strtolower((string) $user->first_name) === $name_lower);

        return $first_name_matches->count() === 1 ? $first_name_matches->first() : null;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{ids: array<int, string>, unmatched: array<int, string>}
     */
    private function resolvePersonIds(string $raw, Collection $users): array
    {
        $ids = [];
        $unmatched = [];

        foreach ($this->splitList($raw) as $name) {
            $user = $this->matchUser($name, $users);

            if ($user !== null) {
                $ids[] = (string) $user->id;
            } else {
                $unmatched[] = $name;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'unmatched' => $unmatched];
    }

    private function optionColor(string $label, int $index): string
    {
        return self::KNOWN_OPTION_COLORS[mb_strtolower(trim($label))] ?? self::OPTION_COLOR_PALETTE[$index % count(self::OPTION_COLOR_PALETTE)];
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array{id: string, label: string, color: string, is_active: bool}>
     */
    private function buildOptions(array $labels): array
    {
        $options = [];

        foreach (array_values($labels) as $index => $label) {
            $options[] = [
                'id' => (string) Str::uuid(),
                'label' => $label,
                'color' => $this->optionColor($label, $index),
                'is_active' => true,
            ];
        }

        return $options;
    }

    /**
     * The `config` a freshly-created column of `$type` starts with: the
     * option list for Status/Label/Dropdown, a scale wide enough for every
     * imported Rating, and a 24 hour clock for an Hour column whose values
     * were written that way. Nothing for everything else (Tags options are
     * board-wide {@see BoardTag} rows instead).
     *
     * @param  array<int, string>  $option_labels
     * @param  array<int, string>  $values  the raw cells collected under the column
     * @return array<string, mixed>|null
     */
    private function initialColumnConfig(string $type, array $option_labels, array $values = []): ?array
    {
        if ($type === BoardColumn::TYPE_RATING) {
            $highest = array_reduce($values, fn (float $max, string $value) => max($max, (float) ($this->parseNumber($value) ?? 0)), 0.0);

            return $highest > BoardColumn::RATING_DEFAULT_MAX
                ? ['rating_max' => min(BoardColumn::RATING_MAX_LIMIT, (int) ceil($highest))]
                : null;
        }

        if ($type === BoardColumn::TYPE_HOUR) {
            $hours = array_values(array_filter(array_map(fn (string $value) => $this->parseHour($value), $values)));

            return $hours !== [] && ! in_array(true, array_column($hours, 'is_12_hour'), true) ? ['hour_format' => '24'] : null;
        }

        if (! in_array($type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN], true) || $option_labels === []) {
            return null;
        }

        return ['options' => $this->buildOptions(array_slice(array_values(array_unique($option_labels)), 0, 60))];
    }

    private function widthForType(string $type): int
    {
        return match ($type) {
            BoardColumn::TYPE_PEOPLE => 160,
            BoardColumn::TYPE_STATUS => 170,
            BoardColumn::TYPE_LABEL => 130,
            BoardColumn::TYPE_TIMELINE => 200,
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_RATING => 110,
            BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_LINK, BoardColumn::TYPE_FILES => 200,
            BoardColumn::TYPE_DATE, BoardColumn::TYPE_PHONE => 150,
            BoardColumn::TYPE_EMAIL => 190,
            BoardColumn::TYPE_CHECKBOX, BoardColumn::TYPE_VOTE => 90,
            BoardColumn::TYPE_LONG_TEXT => 240,
            BoardColumn::TYPE_LOCATION => 200,
            BoardColumn::TYPE_WEEK => 170,
            BoardColumn::TYPE_HOUR, BoardColumn::TYPE_COLOR_PICKER => 120,
            BoardColumn::TYPE_AUTO_NUMBER => 140,
            default => 160,
        };
    }
}
