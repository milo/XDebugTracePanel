<?php

/*
	Examples of using XDebugTrace panel for Nette 2.0
	http://github.com/mil0/XDebugTracePanel

1. INSTALLATION
	Installation is easy. Copy XDebugTrace.php and *.latte templates into
	directory, where RobotLoader have access. E.g.

		libs/Panels/XDebugTrace/XDebugTrace.php
		libs/Panels/XDebugTrace/content.latte
		libs/Panels/XDebugTrace/error.latte

2. REGISTER PANEL
	In next step, register panel in bootstrap.php. You need a web server
	writable directory for temporary trace file. In XDebugTrace constructor
	provide only file name without extension. xdebug_trace_start() always
	add .xt extension.
*/

define('TMP_DIR', __DIR__ . '/../temp');
$xdebugTrace = new \Panel\XDebugTrace(TMP_DIR . '/xdebug_trace');
\Nette\Diagnostics\Debugger::addPanel($xdebugTrace);

/*
3. START-PAUSE-STOP TRACING
	Now, when panel is registered, you can start tracing.
*/
$xdebugTrace->start();
$router = $container->router;
$router[] = new Route('index.php', 'Homepage:default', Route::ONE_WAY);
$router[] = new Route('<presenter>/<action>[/<id>]', 'Homepage:default');
$xdebugTrace->pause();

$xdebugTrace->start('Application run');
$application->run();
$xdebugTrace->stop();

/*
	Because of xdebug_trace_start() can run only once, only one instance
	of XDebugTrace class can exists. You can call all methods
	statically as XDebugTrace::callMethodName(). E.g.
*/
\Panel\XDebugTrace::callStart('Application run');
\Panel\XDebugTrace::callPause();
\Panel\XDebugTrace::callStop();

/*
4. OWN TEMPLATES
	You can use all of \Nette\Templating\FileTemplate advantages and set own
	latte template file or register own helpers.
*/
$template = $xdebugTrace->getTemplate();
$template->setFile('templates/my-template.latte');

/*
5. TRACE RECORDS FILTERING
	Filtering is a most ambitious work with this panel. Without this, HTML
	output can be huge (megabytes). XDebugTrace panel provide simple mechanism
	for trace records filtering. You can register own filtering callbacks.

	At first, take a look on XDebugTrace::defaultFilterCb() source code. This is
	a default filtering callback. Is used when you dont register own one.

	At second, is good to know xdebug trace file structure:

		[Entry record]
		level   id  0   time    memory  functionName    ...

		[Exit record]
		level   id  1   time    memory

		[Example]
		3    121    0    0.012    401442    myFunction - myFunction() enter
		4    122    0    0.014    401442    strpos     - strpos() enter
		4    122    1    0.015    401454               - strpos() exit
		4    123    0    0.016    401454    substr     - substr() enter
		4    123    1    0.018    401505               - substr() exit
		3    121    1    0.020    401442               - myFunction() exit

		Detailed on http://xdebug.org/docs/execution_trace

	Use these functions for set-up own filters:
		$debugTrace->addFilterCallback($callback, $flags)
		$debugTrace->setFilterCallback($callback, $flags)

	$flags is a bitmask of \Panel\XDebugTrace::FILTER_* constants.
		FILTER_ENTRY - call filter on entry records (default)
		FILTER_EXIT  - call filter on exit records
		FILTER_BOTH  - same as FILTER_ENTRY | FILTER_EXIT

		FILTER_APPEND_ENTRY - append filter behind others (default is prepend)
		FILTER_APPEND_EXIT  - append filter behind others (default is prepend)
		FILTER_APPEND       - same as FILTER_APPEND_ENTRY | FILTER_APPEND_EXIT

		FILTER_REPLACE_ENTRY - remove all entry filters
		FILTER_REPLACE_EXIT  - remove all exit filters
		FILTER_REPLACE       - same as FILTER_REPLACE_ENTRY | FILTER_REPLACE_EXIT

	Your callback should return bitmask of flags:
		\Panel\XDebugTrace::SKIP - skip this record
		\Panel\XDebugTrace::STOP - don't call followed filters

	Examples follow.
*/

// Display only count() function traces
$xdebugTrace->setFilterCallback(function($record) {
	if ($record->function !== 'count') {
		return \Panel\XDebugTrace::SKIP;
	}
});



// We want display every Nette\Utils\LimitedScope::load() calling in detail.
// Note the second $onEntry parametr. Is TRUE for entry recods, FALSE for exit.
// Note the FILTER_BOTH flag. It menans: "Call filter on entry and exit records"
$xdebugTrace->setFilterCallback(function($record, $onEntry){
	static $cnt = 0;

	if ($record->function === 'Nette\Utils\LimitedScope::load') {
		$cnt += $onEntry ? 1 : -1;
		return NULL;
	}

	return $cnt ? NULL : \Panel\XDebugTrace::SKIP;
}, \Panel\XDebugTrace::FILTER_BOTH);



// Display everything except for internal functions
$xdebugTrace->setFilterCallback(function($record){
    return $record->isInternal ? \Panel\XDebugTrace::SKIP : NULL;
});



// Display only functions which run longer then 1 ms.
// Must be registered with FILTER_EXIT. ENTRY records have deltaTime = NULL
$xdebugTrace->setFilterCallback(function($record) {
    if ($record->deltaTime < 0.001) {
        return \Panel\XDebugTrace::SKIP;
    }
}, \Panel\XDebugTrace::FILTER_EXIT);
