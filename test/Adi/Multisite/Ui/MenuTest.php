<?php

/**
 * @author Christopher Klein <ckl@neos-it.de>
 * @access private
 */
class Ut_Adi_Multisite_Ui_MenuTest extends Ut_BasicTest
{
	/* @var Multisite_Ui_BlogProfileRelationshipPage | PHPUnit_Framework_MockObject_MockObject */
	private $blogProfileRelationshipPage;

	/* @var Multisite_Ui_ProfileConfigurationPage | PHPUnit_Framework_MockObject_MockObject */
	private $profileConfigurationPage;

	public function setUp()
	{
		parent::setUp();

		$this->blogProfileRelationshipPage = $this->createMock('Multisite_Ui_BlogProfileRelationshipPage');
		$this->profileConfigurationPage = $this->createMock('Multisite_Ui_ProfileConfigurationPage');
	}

	public function tearDown()
	{
		parent::tearDown();
	}

	/**
	 *
	 * @param null $methods
	 *
	 * @return Adi_Multisite_Ui_Menu|PHPUnit_Framework_MockObject_MockObject
	 */
	private function sut($methods = null)
	{
		return $this->getMockBuilder('Adi_Multisite_Ui_Menu')
			->setConstructorArgs(
				array(
					$this->createMock('Multisite_Option_Provider'),
					$this->blogProfileRelationshipPage,
					$this->profileConfigurationPage,
				)
			)
			->setMethods($methods)
			->getMock();
	}

	/**
	 * @test
	 */
	public function register_itAddsTheMenus()
	{
		$sut = $this->sut(array('addAjaxListeners'));

		\WP_Mock::expectActionAdded('network_admin_menu', array($sut, 'registerMenu'));

		$sut->register();
	}

	/**
	 * @test
	 */
	public function register_itAddsTheAjaxListeners()
	{
		$sut = $this->sut(array('addAjaxListener'));

		$sut->expects($this->exactly(2))
			->method('addAjaxListener')
			->withConsecutive(
				$this->blogProfileRelationshipPage,
				$this->profileConfigurationPage
			);

		$sut->register();
	}

	/**
	 * @test
	 */
	public function registerMenu_addsMenusToWordPress()
	{
		$sut = $this->sut(array('addSubMenu'));

		$permission = 'manage_network';
		$networkMenu = 'Active Directory Integration';

		WP_Mock::wpFunction('add_menu_page', array(
			'args'  => array($networkMenu, $networkMenu, $permission, 'adi2_slug'),
			'times' => '1',
		));

		$this->blogProfileRelationshipPage->expects($this->once())
			->method('getSlug')
			->willReturn('adi2_slug');

		$sut->expects($this->exactly(2))
			->method('addSubMenu')
			->withConsecutive(
				array('adi2_slug', 'manage_network', $this->blogProfileRelationshipPage, 'renderNetwork'),
				array('adi2_slug', 'manage_network', $this->profileConfigurationPage, 'renderNetwork')
			)
			->willReturn('adi2_page', '', '', '');

		WP_Mock::expectActionAdded('admin_enqueue_scripts', array($sut, 'loadScriptsAndStyle'));

		$sut->registerMenu();
	}

	/**
	 * @test
	 */
	public function loadScriptsAndStyle_itloadsAllJavaScriptAndCssFilesFromNecessaryPages()
	{
		$sut = $this->sut();
		$hook = 'testHook';
		$objectMethod = 'loadNetworkScriptsAndStyle';

		$this->blogProfileRelationshipPage->expects($this->once())
			->method($objectMethod)
			->with($hook);

		$this->profileConfigurationPage->expects($this->once())
			->method($objectMethod)
			->with($hook);

		$sut->loadScriptsAndStyle($hook);
	}
}