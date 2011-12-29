<?php

/**
 * XDebug Trace panel for Nette 2.0 framework.
 *
 * @author Miloslav Hůla
 * @version 0.3-beta
 * @see http://github.com/mil0/XDebugTracePanel
 * @licence LGPL
 *
 * <code>
 * // Register panel in bootstrap.php
 * // xdebug_start_trace() always add .xt extension
 * $xdebugPanel = new \Panel\XDebugTrace(TMP_DIR . '/xdebug_trace');
 * \Nette\Diagnostics\Debugger::addPanel($xdebugPanel);
 *
 * // And somewhere in code
 * $xdebugPanel->start();
 * ...
 * ...
 * ...
 * $xdebugPanel->pause();
 *
 *
 *
 * $xdebugPanel->start();
 * ...
 * ...
 * ...
 * $xdebugPanel->stop();
 *
 * // Shortcuts for start(), pause(), stop()
 * \Panel\XDebugTrace::callStart();
 * \Panel\XDebugTrace::callPause();
 * \Panel\XDebugTrace::callStop();
 *
 * // See \Panel\XDebugTrace::defaultFilterCb() for filtering options.
 * $xdebugPanel->addFilterCallback(
 *     function($record) {
 *        if ($record->function === 'dontCareFunction') {
 *            return \Panel\XDebugTrace::FILTER_SKIP;
 *        }
 *    }
 * );
 * </code>
 */

namespace Panel;

use
	Nette\Object,
	Nette\Diagnostics\IBarPanel,
	Nette\Diagnostics\Debugger,
	Nette\Templating\FileTemplate,
	Nette\Latte\Engine;


class XDebugTrace extends Object implements IBarPanel
{
	/** Tracing states */
	const
		STATE_STOP = 0,
		STATE_RUN = 1,
		STATE_PAUSE = 2;


	/** Filter callback action bitmask */
	const
		FILTER_STOP = 0x01,
		FILTER_SKIP = 0x02;


	/** Adding filter bitmask flags */
	const
		FILTER_APPEND = 0x01,
		FILTER_ENTRY = 0x02,
		FILTER_EXIT = 0x04,
		FILTER_BOTH = 0x06,
		FILTER_REPLACE = 0x08;


	/**
	 * @var int maximal length of line in trace file
	 */
	public static $traceLineLength = 4096;


	/**
	 * @var bool delete trace file in destructor or not
	 */
	public $deleteTraceFile = false;


	/**
	 * @var \Panel\XDebugTrace
	 */
	private static $instance;


	/**
	 * @var int tracing state
	 */
	private $state = self::STATE_STOP;


	/**
	 * @var string path to trace file
	 */
	private $traceFile;


	/**
	 * @var array of stdClass
	 */
	protected $traces = array();


	/**
	 * @var reference to $this->traces
	 */
	protected $trace;


	/**
	 * @var array of level => indent size
	 */
	protected $indents = array();


	/**
	 * @var reference to $this->indents
	 */
	protected $indent;


	/**
	 * @var bool internal class error occured, error template will be rendered
	 */
	protected $isError = false;


	/**
	 * @var string
	 */
	protected $errMessage = '';


	/**
	 * @var string
	 */
	protected $errFile;


	/**
	 * @var int
	 */
	protected $errLine;


	/**
	 * @var array of callbacks called when parsing entry record from trace file
	 */
	protected $filterEntryCallbacks = array();


	/**
	 * @var array of callbacks called when parsing exit record from trace file
	 */
	protected $filterExitCallbacks = array();


	/**
	 * @var bool skip PHP internals functions when parsing
	 */
	protected $skipInternals = true;


	/**
	 * @var \Nette\Templating\FileTemplate
	 */
	protected $lazyTemplate;


	/**
	 * @var \Nette\Templating\FileTemplate
	 */
	protected $lazyErrorTemplate;



	/**
	 * @param  string path to trace file
	 * @param  bool skip PHP internal functions when parsing trace file
	 * @throws \Nette\InvalidStateException
	 */
	public function __construct($traceFile, $skipInternals = true)
	{
		if (self::$instance !== NULL) {
			throw new \Nette\InvalidStateException('Class ' . get_class($this) . ' can be instantized only once, xdebug_start_trace() can run only once.');
		}

		self::$instance = $this;

		if (!extension_loaded('xdebug')) {
			$this->setError('XDebug extension is not loaded');

		} elseif (@file_put_contents($traceFile . '.xt', '') === false) {
			$this->setError("Cannot create trace file '$traceFile.xt'", error_get_last());

		} else {
			$this->traceFile = $traceFile;
		}

		$this->skipInternals($skipInternals);
		$this->addFilterCallback(array($this, 'defaultFilterCb'));
	}



	public function __destruct()
	{
		if ($this->deleteTraceFile && is_file($this->traceFile . '.xt')) {
			@unlink($this->traceFile . '.xt');
		}
	}



	public static function __callStatic($name, $args)
	{
		if (self::$instance !== NULL && preg_match('/^call([A-Z].*)/', $name, $match)) {
			$method = lcfirst($match[1]);
			if (method_exists(self::$instance, $method)) {
				return call_user_func_array(array(self::$instance, $method), $args);
			}
		}

		parent::__callStatic($name, $args);
	}



	/**
	 * Access to class instance.
	 *
	 * @return \Panel\XDebugTrace
	 * @throws \Nette\InvalidStateException
	 */
	public static function getInstance()
	{
		if (self::$instance === NULL) {
			throw new \Nette\InvalidStateException(get_called_class() . ' has not been instantized yet.');
		}

		return self::$instance;
	}



	/* ~~~ Start/Stop tracing part ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */
	/**
	 * Start or continue tracing.
	 */
	public function start()
	{
		if (!$this->isError) {
			if ($this->state === self::STATE_STOP) {
				xdebug_start_trace($this->traceFile, XDEBUG_TRACE_COMPUTERIZED);

			} elseif ($this->state === self::STATE_PAUSE) {
				xdebug_start_trace($this->traceFile, XDEBUG_TRACE_COMPUTERIZED | XDEBUG_TRACE_APPEND);
			}

			$this->state = self::STATE_RUN;
		}
	}



	/**
	 * Pause tracing.
	 */
	public function pause()
	{
		if ($this->state === self::STATE_RUN) {
			xdebug_stop_trace();
			$this->state = self::STATE_PAUSE;
		}
	}



	/**
	 * Stop tracing.
	 */
	public function stop()
	{
		if ($this->state !== self::STATE_STOP) {
			xdebug_stop_trace();
		}

		$this->state = self::STATE_STOP;
	}



	/*~~~ Rendering part ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */
	/**
	 * Lazy error template cooking.
	 */
	public function getErrorTemplate()
	{
		if ($this->lazyErrorTemplate === NULL) {
			$this->lazyErrorTemplate = new FileTemplate(dirname(__FILE__) . '/error.latte');
			$this->lazyErrorTemplate->registerFilter(new Engine());
		}

		return $this->lazyErrorTemplate;
	}



	/**
	 * Lazy content template cooking.
	 */
	public function getTemplate()
	{
		if ($this->lazyTemplate === NULL) {
			$this->lazyTemplate = new FileTemplate(dirname(__FILE__) . '/content.latte');
			$this->lazyTemplate->registerFilter(new Engine());
			$this->lazyTemplate->registerHelperLoader('Nette\Templating\DefaultHelpers::loader');
			$this->lazyTemplate->registerHelper('time', array($this, 'timeHelper'));
			$this->lazyTemplate->registerHelper('timeClass', array($this, 'timeClassHelper'));
			$this->lazyTemplate->registerHelper('basename', array($this, 'basenameHelper'));
		}

		return $this->lazyTemplate;
	}



	/**
	 * Template helper converts seconds to ns, us, ms, s.
	 *
	 * @param  float time interval in seconds
	 * @param  decimal part precision
	 * @return string formated time
	 */
	public function timeHelper($time, $precision = 0)
	{
		$units = 's';
		if ($time < 0.000001) {	// <1us
			$units = 'ns';
			$time *= 1000000000;

		} elseif ($time < 0.001) { // <1ms
			$units = "\xc2\xb5s";
			$time *= 1000000;

		} elseif ($time < 1) { // <1s
			$units = 'ms';
			$time *= 1000;
		}

		return round($time, $precision) . ' ' . $units;
	}



	/**
	 * Template helper converts seconds to HTML class.
	 *
	 * @param  float time interval in seconds
	 * @param  float over this value is interval classified as slow
	 * @param  float under this value is interval classified as fast
	 * @return string
	 */
	public function timeClassHelper($time, $slow = NULL, $fast = NULL)
	{
		$slow = $slow ?: 0.02;	// 20ms
		$fast = $fast ?: 0.001;	//  1ms

		if ($time <= $fast) {
			return 'timeFast';

		} elseif ($time <= $slow) {
			return 'timeMedian';
		}

		return 'timeSlow';
	}



	/**
	 * Template helper extracts base filename from file path.
	 *
	 * @param  string path to file
	 * @return string
	 */
	public function basenameHelper($path)
	{
		return basename($path);
	}



	/**
	 * Sets internal error variables.
	 *
	 * @param  string error message
	 * @param  array error_get_last()
	 */
	protected function setError($message, array $lastError = NULL)
	{
		$this->isError = true;
		$this->errMessage = $message;

		if ($lastError !== NULL) {
			$this->errMessage .= ': ' . $lastError['message'];
			$this->errFile = $lastError['file'];
			$this->errLine = $lastError['line'];
		}
	}



	/**
	 * Render error message.
	 *
	 * @return  string rendered error template
	 */
	protected function renderError()
	{
		$template = $this->getErrorTemplate();
		$template->errMessage = $this->errMessage;
		$template->errFile = $this->errFile;
		$template->errLine = $this->errLine;

		ob_start();
		$template->render();
		return ob_get_clean();
	}



	/**
	 * Implements Nette\Diagnostics\IBarPanel
	 */
	public function getTab()
	{
		$dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAACXBIWXMAAA7DAAAOwwHHb6hkAAAB2ElEQVQ4jaWS309SYRjHvemura2fE8tQJA/ojiBEoQ3n2uqm2mytixot10VbF7W6a5ZmukiWoUaGGCXnMAacAxM6tIPZD50X9ld94rSBMU4/lhffd++zd89nz/f7Pi1Ay25Uv4g2S7Uyrn9vsuzbQwNg6m6Q71qKK34b7m7rbyH2I3u5dSmAEn/JSMBFHTAo2tn+rLEuR3h17zJD3p4mSF/HIbKJKJW1dTR5kSGPcwdgyG1rZaOskow8YcGAeBx1iGg9iLoSQynqqEsznO7tbLRQU7+9jW+lDInwI6IPruJzWnEc3U9ejpMpaCiLzxhwCQ3TNfl0d1nQlSTS7GNSU7fJJpcplCrkY8+bmk0BNciq9AatXKFU2SAVmcDrtJmGawrwO9tRpTgl/StqLkf6dYh+4fi/AbzdbajJJQrlT+RiYZTJGyTmQ2RjL/CLXX+2cMrRTjH9DrXq+ePKHK7Ow3gEKzN3RpibHiMdDTHYZzcP0Scco5iRyH9YQ5cWMOram/Gly2M3mX54n7ezTxn4ZZKfx7DXwfYXna3NzWrzPP7e5sDOuAXkiVHkyDjx8DhnfT07ixS8EGBr9T3B8ycRT5iHZaij9QDXzvlQo5NcvzjcuIn/q10DfgAvvbB+PnEGHAAAAABJRU5ErkJggg==';
		return "<img src=\"$dataUri\">XDebugTrace";
	}



	/**
	 * Implements Nette\Diagnostics\IBarPanel
	 */
	public function getPanel()
	{
 		$this->stop();

		if ($this->isError) {
			return $this->renderError();
		}

		$parsingStart = microtime(true);

		$fd = @fopen($this->traceFile . '.xt', 'rb');
		if ($fd === false) {
			$this->setError("Cannot open trace file '$this->traceFile.xt'", error_get_last());

		} elseif (!filesize($this->traceFile . '.xt')) {
			$this->setError("Trace file '$this->traceFile.xt' is empty");

		} elseif (!preg_match('/^Version: 2\..*/', (string) fgets($fd, self::$traceLineLength))) {
			$this->setError('Trace file version line mischmasch');

		} elseif (!preg_match('/^File format: 2/', (string) fgets($fd, self::$traceLineLength))) {
			$this->setError('Trace file format line mischmasch');

 		} else {
			while (($line = fgets($fd, self::$traceLineLength)) !== false) {
				if (strncmp($line, 'TRACE START', 11) === 0) {	// TRACE START line
					$this->openTrace();

				} elseif (strncmp($line, 'TRACE END', 9) === 0) {	// TRACE END line
					$this->closeTrace();

				} elseif ($this->isTraceOpened()) {
					$line = rtrim($line, "\r\n");

					$cols = explode("\t", $line);
					if (!strlen($cols[0]) && count($cols) === 5) {	// last line before TRACE END
/*
						$record = (object) array(
							'time' => (float) $cols[3],
							'memory' => (float) $cols[4],
						);
						$this->addRecord($record, true);
*/
						continue;

					} else {
						$record = (object) array(
							'level' => (int) $cols[0],
							'indent' => 0,
							'id' => (float) $cols[1],
							'isEntry' => !$cols[2],
							'time' => (float) $cols[3],
							'deltaTime' => NULL,
							'memory' => (float) $cols[4],
							'deltaMemory' => NULL,
						);

						if ($record->isEntry) {
							$record->function = $cols[5];
							$record->isInternal = !$cols[6];
							$record->includeFile = strlen($cols[7]) ? $cols[7] : NULL;
							$record->filename = $cols[8];
							$record->line = $cols[9];
							$record->evalInfo = '';

							if (strcmp(substr($record->filename, -13), "eval()'d code") === 0) {
								preg_match('/(.*)\(([0-9]+)\) : eval\(\)\'d code$/', $record->filename, $match);
								$record->evalInfo = "- eval()'d code ($record->line)";
								$record->filename = $match[1];
								$record->line = $match[2];
							}
						}

						$this->addRecord($record);
					}
				}
 			}

			$template = $this->getTemplate();
			$template->traces = $this->traces;
			$template->indents = $this->indents;
		}

		if ($this->isError) {
			return $this->renderError();
		}

		$template->parsingTime = microtime(true) - $parsingStart;

		ob_start();
		$template->render();
		return ob_get_clean();
	}



	/**
	 * Sets trace and indent references.
	 */
	protected function openTrace()
	{
		$index = count($this->traces);

		$this->traces[$index] = array();
		$this->trace =& $this->traces[$index];

		$this->indents[$index] = array();
		$this->indent =& $this->indents[$index];
	}



	/**
	 * Unset trace and indent references and compute indents.
	 */
	protected function closeTrace()
	{
		$null = NULL;
		$this->trace =& $null;

		if (count($this->indent)) {
			ksort($this->indent);
			$this->indent = array_combine(array_keys($this->indent), range(0, count($this->indent) - 1));
		}

		$this->indent =& $null;
	}



	/**
	 * Check if internal references are sets.
	 * @return bool
	 */
	protected function isTraceOpened()
	{
		return $this->trace !== NULL;
	}



	/**
	 * Push parsed trace file line into trace stack.
	 *
	 * @param  stdClass parsed trace file line
	 */
	protected function addRecord(\stdClass $record)
	{
		if ($record->isEntry) {
			$add = true;
			foreach ($this->filterEntryCallbacks AS $callback) {
				$result = (int) call_user_func($callback, $record, $this);
				if ($result & self::FILTER_SKIP) {
					$add = false;
				}

				if ($result & self::FILTER_STOP) {
					break;
				}
			}

			if ($add) {
				$this->indent[$record->level] = 1;
				$this->trace[$record->id] = $record;
			}

		} elseif (isset($this->trace[$record->id])) {
			foreach ($this->filterExitCallbacks AS $callback) {
				$result = (int) call_user_func($callback, $record, $this);
				if ($result & self::FILTER_STOP) {
					break;
				}
			}

			$r = $this->trace[$record->id];
			$r->deltaTime = $record->time - $r->time;
			$r->deltaMemory = $record->memory - $r->memory;
		}
	}



	/* ~~~ Trace records filtering ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */
	/**
	 * Setting of default filter callback.
	 * @param  bool skip PHP internal functions
	 */
	public function skipInternals($skip)
	{
		$this->skipInternals = $skip;
	}



	/**
	 * Default filter
	 *
	 * @param  stdClass trace file record
	 * @return int bitmask of self::FILTER_*
	 */
	protected function defaultFilterCb(\stdClass $record)
	{
		if ($this->skipInternals && $record->isInternal) {
			return self::FILTER_SKIP;
		}

		if ($record->filename === __FILE__) {
			return self::FILTER_SKIP;
		}

		if (strncmp($record->function, 'Nette\\', 6) === 0) {
			return self::FILTER_SKIP;
		}

		if (strncmp($record->function, 'Panel\\XDebugTrace::', 19) === 0) {
			return self::FILTER_SKIP;
		}

		if (strncmp($record->function, 'Panel\\XDebugTrace->', 19) === 0) {
			return self::FILTER_SKIP;
		}

		if (strcmp($record->function, 'callback') === 0) {
			return self::FILTER_SKIP;
		}

		if ($record->includeFile !== NULL) {
			return self::FILTER_SKIP;
		}
	}



	/**
	 * Register own filter callback.
	 *
	 * @param  callback(stdClass $record, \Panel\XDebugTrace $this)
	 * @param  int bitmask of self::FILTER_*
	 */
	public function addFilterCallback($callback, $flags = self::FILTER_ENTRY)
	{
		$flags = (int) $flags;

		// Entry records filter
		if ($flags & self::FILTER_ENTRY) {
			if ($flags & self::FILTER_REPLACE) {
				$this->filterEntryCallbacks = array();
			}

			if ($flags & self::FILTER_APPEND) {
				$this->filterEntryCallbacks[] = $callback;

			} else {
				array_unshift($this->filterEntryCallbacks, $callback);
			}
		}

		// Exit records filter
		if ($flags & self::FILTER_EXIT) {
			if ($flags & self::FILTER_REPLACE) {
				$this->filterExitCallbacks = array();
			}

			if ($flags & self::FILTER_APPEND) {
				$this->filterExitCallbacks[] = $callback;

			} else {
				array_unshift($this->filterExitCallbacks, $callback);
			}
		}
	}

}