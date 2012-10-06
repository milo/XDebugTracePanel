<?php

namespace Panel;



use Nette\Config\CompilerExtension,
	Nette\DI\Container,
	Nette\Utils\PhpGenerator\ClassType;



/**
 * XDebug Trace Nette extension.
 *
 * @author  Miloslav Hůla
 * @see     http://github.com/milo/XDebugTracePanel
 * @licence LGPL
 */
class XDebugTraceExtension extends CompilerExtension
{
	private $defaults = array(
		'traceFile' => '%tempDir%/xdebugTrace.xt',
		'onCreate' => NULL,
	);



	public function afterCompile(ClassType $class)
	{
		$config = $this->getConfig($this->defaults);

		$method = $class->methods[Container::getMethodName($this->name)];
		$method->setBody('');
		$method->addBody('$service = new Panel\XDebugTrace(?);', array($config['traceFile']));

		if (!empty($config['onCreate'])) {
			$method->addBody('call_user_func(?, $service);', array($config['onCreate']));
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
