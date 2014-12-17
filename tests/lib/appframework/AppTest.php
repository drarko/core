<?php

/**
 * ownCloud - App Framework
 *
 * @author Bernhard Posselt
 * @copyright 2012 Bernhard Posselt <dev@bernhard-posselt.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OC\AppFramework;

function rrmdir($directory) {
	$files = array_diff(scandir($directory), array('.','..'));
	foreach ($files as $file) {
		if (is_dir($directory . '/' . $file)) {
			rrmdir($directory . '/' . $file);
		} else {
			unlink($directory . '/' . $file);
		}
	}
	return rmdir($directory);
}

class AppTest extends \Test\TestCase {

	private $container;
	private $api;
	private $controller;
	private $dispatcher;
	private $params;
	private $headers;
	private $output;
	private $controllerName;
	private $controllerMethod;
	private $appPath;

	protected function setUp() {
		parent::setUp();

		$this->container = new \OC\AppFramework\DependencyInjection\DIContainer('test', array());
		$this->controller = $this->getMockBuilder(
			'OCP\AppFramework\Controller')
			->disableOriginalConstructor()
			->getMock();
		$this->dispatcher = $this->getMockBuilder(
			'OC\AppFramework\Http\Dispatcher')
			->disableOriginalConstructor()
			->getMock();


		$this->headers = array('key' => 'value');
		$this->output = 'hi';
		$this->controllerName = 'Controller';
		$this->controllerMethod = 'method';

		$this->container[$this->controllerName] = $this->controller;
		$this->container['Dispatcher'] = $this->dispatcher;
		$this->container['urlParams'] = array();

		$this->appPath = __DIR__ . '/../../../apps/namespacetestapp/appinfo';
		$infoXmlPath = $this->appPath . '/info.xml';
		mkdir($this->appPath, 0777, true);

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
		'<info>' .
		    '<id>namespacetestapp</id>' .
			'<namespace>NameSpaceTestApp</namespace>' .
		'</info>';
		file_put_contents($infoXmlPath, $xml);
	}


	public function testControllerNameAndMethodAreBeingPassed(){
		$return = array(null, array(), array(), null);
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($this->controller),
				$this->equalTo($this->controllerMethod))
			->will($this->returnValue($return));

		$this->expectOutputString('');

		App::main($this->controllerName, $this->controllerMethod,
			$this->container);
	}


	public function testBuildAppNamespace() {
		$ns = App::buildAppNamespace('someapp');
		$this->assertEquals('OCA\Someapp', $ns);
	}


	public function testBuildAppNamespaceCore() {
		$ns = App::buildAppNamespace('someapp', 'OC\\');
		$this->assertEquals('OC\Someapp', $ns);
	}


	public function testBuildAppNamespaceInfoXml() {
		$ns = App::buildAppNamespace('namespacetestapp', 'OCA\\');
		$this->assertEquals('OCA\NameSpaceTestApp', $ns);
	}


	protected function tearDown() {
		rrmdir($this->appPath);
	}

	/*
	FIXME: this complains about shit headers which are already sent because
	of the content length. Would be cool if someone could fix this

	public function testOutputIsPrinted(){
		$return = array(null, array(), $this->output);
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($this->controller),
				$this->equalTo($this->controllerMethod))
			->will($this->returnValue($return));

		$this->expectOutputString($this->output);

		App::main($this->controllerName, $this->controllerMethod, array(),
			$this->container);
	}
	*/

	// FIXME: if someone manages to test the headers output, I'd be grateful


}
