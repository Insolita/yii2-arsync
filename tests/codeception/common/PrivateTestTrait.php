<?php
/**
 * Created by solly [11.05.16 0:31]
 */

namespace tests\codeception\common;

/**
 * trait for simplify testing private and protected methods
 **/
trait PrivateTestTrait
{
	public function callPrivateMethod($object, $method, $args=[])
	{
		$classReflection = new \ReflectionClass(get_class($object));
		$methodReflection = $classReflection->getMethod($method);
		$methodReflection->setAccessible(true);
		$result = $methodReflection->invokeArgs($object, $args);
		$methodReflection->setAccessible(false);
		return $result;
	}
}