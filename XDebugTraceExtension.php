<?php

namespace Panel;

use Nette\Framework,
	Nette\Config\CompilerExtension,
	Nette\DI\Container,
	Nette\PhpGenerator\ClassType;



/**
 * XDebug Trace panel extension for Nette framework.
 *
 * @author   Miloslav Hůla
 * @version  $Format:%m$
 * @see      http://github.com/milo/XDebugTracePanel
 * @licence  LGPL
 */
class XDebugTraceExtension extends CompilerExtension
{
	private $defaults = array(
		'traceFile' => '%tempDir%/xdebugTrace.xt',
		'onCreate' => NULL,
		'statistics' => NULL,
	);


	public function afterCompile(ClassType $class)
	{
		$config = $this->getConfig($this->defaults);

		$name = Container::getMethodName($this->name);
		$class->addMethod($name);

		$method = $class->methods[$name];
		$method->setBody('');
		$method->addBody('$service = new Panel\XDebugTrace(?);', array($config['traceFile']));

		if (!empty($config['onCreate'])) {
			$method->addBody('call_user_func(?, $service);', array($config['onCreate']));
		}

		if (!empty($config['statistics'])) {
			$args = is_array($config['statistics']) ? ($config['statistics'] + array(TRUE, NULL)) : array($config['statistics'], NULL);
			$method->addBody('$service->enableStatistics(?, ?);', $args);
		}

		$method->addBody('return $service;');
		$method->documents = array('@return Panel\XDebugTrace');

		foreach ($class->documents as $k => $v) {
			if (preg_match('~@property.*\$' . preg_quote($this->name, '~') . '$~', $v)) {
				$class->documents[$k] = '@property Panel\XDebugTrace $' . $this->name;
				break;
			}
		}

		$class->methods['initialize']->addBody('Nette\Diagnostics\Debugger::addPanel($this->getService(?));', array($this->name));
	}

}
