<?php

/**
 * Ut_Role_ManagerTest
 *
 * @author Christopher Klein <ckl@neos-it.de>
 * @access private
 */
class Ut_Role_ManagerTest extends Ut_BasicTest
{
	/* @var Multisite_Configuration_Service|PHPUnit_Framework_MockObject_MockObject $configuration */
	private $configuration;

	/* @var adLDAP|PHPUnit_Framework_MockObject_MockObject $adLdap */
	private $adLdap;

	/* @var Ldap_Connection|PHPUnit_Framework_MockObject_MockObject $ldapConnection */
	private $ldapConnection;

	public function setUp()
	{
		if (!class_exists('adLDAP')) {
			//get adLdap
			require_once ADI_PATH . '/vendor/adLDAP/adLDAP.php';
		}

		parent::setUp();

		$this->configuration = parent::createMock('Multisite_Configuration_Service');
		$this->adLdap = parent::createMock('adLDAP');

		$this->ldapConnection = $this->getMockBuilder('Ldap_Connection')
			->setConstructorArgs(array($this->configuration))
			->getMock();

		$this->ldapConnection->method('getAdLdap')->willReturn($this->adLdap);
	}

	public function tearDown()
	{
		parent::tearDown();
	}

	/**
	 * @param $methods
	 *
	 * @return Adi_Role_Manager|PHPUnit_Framework_MockObject_MockObject
	 */
	public function sut($methods)
	{
		return $this->getMockBuilder('Adi_Role_Manager')
			->setConstructorArgs(array($this->configuration, $this->ldapConnection))
			->setMethods($methods)
			->getMock();
	}

	/**
	 * @test
	 */
	public function createRoleMapping_looksupSecurityGroups()
	{
		$sut = $this->sut(null);

		$this->adLdap->expects($this->once())
			->method("user_groups")
			->with('username')
			->willReturn(array('A', 'B'));

		$actual = $sut->createRoleMapping("username");
		$this->assertEquals(array('A', 'B'), $actual->getSecurityGroups());
	}

	/**
	 * @test
	 */
	public function isInAuthorizationGroup_itReturnsFalse_ifHeIsNotMemberOfAuthorizationGroup()
	{
		$sut = $this->sut(array('getAdLdap', 'isUserInGroup'));

		$this->configuration->expects($this->once())
			->method('getOptionValue')
			->with('authorization_group')
			->willReturn('Test Group;Test Group 2;Test Group   3;');

		$mapping = new Adi_Role_Mapping("username");
		$mapping->setSecurityGroups(array('Unassigned group'));

		$value = $sut->isInAuthorizationGroup($mapping);

		$this->assertEquals(false, $value);
	}

	/**
	 * @test
	 */
	public function isInAuthorizationGroup_itReturnsTrue_ifHeIsMemberOfOneAuthorizationGroup()
	{
		$sut = $this->sut(null);

		$this->configuration->expects($this->once())
			->method('getOptionValue')
			->with('authorization_group')
			->willReturn('Test Group;Test Group 2;assigned group;Test Group   3;');

		$mapping = new Adi_Role_Mapping("username");
		$mapping->setSecurityGroups(array('assigned group'));

		$value = $sut->isInAuthorizationGroup($mapping);

		$this->assertEquals(true, $value);
	}

	/**
	 * @issue ADI-248
	 * @test
	 */
	public function ADI248_whenAuthorizationGroupIsEmpty_itIsNotPossibleToAuthorize() {
		$sut = $this->sut(null);

		$this->configuration->expects($this->once())
			->method('getOptionValue')
			->with('authorization_group')
			// empty authorization groups
			->willReturn('  ; ;;');

		$mapping = new Adi_Role_Mapping("username");

		$value = $sut->isInAuthorizationGroup($mapping);

		$this->assertEquals(true, $value);
	}

	/**
	 * @test
	 */
	public function synchronizeRoles_loadsWordPressRoles()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array());

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('loadWordPressRoles');

		$sut->synchronizeRoles($wpUser, $roleMapping, true);
	}


	/**
	 * @test
	 */
	public function synchronizeRoles_onUserCreation_withNoREGsNoRoleIsAssigned()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array('security-group' => 'wordpress-role'));

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array());

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('updateRoles')
			->with($wpUser, array(), true);

		$sut->synchronizeRoles($wpUser, $roleMapping, true);
	}


	/**
	 * @test
	 */
	public function synchronizeRoles_onUserCreation_withoutREGsTheDefaultRoleSubscriberIsUsed()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array());

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array());

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('updateRoles')
			->with($wpUser, array('subscriber'), true);

		$sut->synchronizeRoles($wpUser, $roleMapping, true);
	}


	/**
	 * @test
	 */
	public function synchronizeRoles_onUserUpdate_withREGsAndUserHasNoRole_noRolesAreSet()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array('security-group' => 'wordpress-role'));

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array());

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('updateRoles')
			->with($wpUser, array(), true);

		$sut->synchronizeRoles($wpUser, $roleMapping, true);
	}

	/**
	 * @test
	 */
	public function synchronizeRoles_onUserUpdate_withoutREGs_allOldRolesArePreserved()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array());

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array());

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('updateRoles')
			->with($wpUser, array(), false);

		$sut->synchronizeRoles($wpUser, $roleMapping, false);
	}


	/**
	 * @test
	 */
	public function synchronizeRoles_setRolesIfPresent()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups', 'updateRoles', 'loadWordPressRoles'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array('security-group'  => 'wordpress-role', 'security-group2' => 'wordpress-role2',
							   'security-group3' => 'wordpress-role3'));

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setWordPressRoles(array('wordpress-role', 'wordpress-role2'));

		$wpUser = $this->createAnonymousMock(array());
		$wpUser->ID = 1;

		$sut->expects($this->once())
			->method('updateRoles')
			->with($wpUser, array('wordpress-role', 'wordpress-role2'), true);

		$sut->synchronizeRoles($wpUser, $roleMapping, false);
	}

	/**
	 * @test
	 */
	public function updateRoles_itDoesNotSetRole_ifCleanExistingRolesIsDisabled()
	{
		$sut = $this->sut(null);
		$wpUser = $this->createAnonymousMock(array('set_role', 'add_role'));

		$wpUser->expects($this->never())
			->method('set_role');

		$wpUser->expects($this->once())
			->method('add_role')
			->with('subscriber');

		$actual = $sut->updateRoles($wpUser, array('subscriber'), false);
		$this->assertEquals(true, $actual);
	}

	/**
	 * @test
	 */
	public function updateRoles_itReleasesExistingRoles_ifCleanExistingRolesIsEnabled()
	{
		$sut = $this->sut(null);
		$wpUser = $this->createAnonymousMock(array('set_role', 'add_role'));

		$wpUser->expects($this->once())
			->method('set_role');

		$wpUser->expects($this->once())
			->method('add_role')
			->with('subscriber');

		$actual = $sut->updateRoles($wpUser, array('subscriber'), true);
		$this->assertEquals(true, $actual);
	}

	/**
	 * @test
	 */
	public function getRoleEquivalentGrous_returnsTheMapping()
	{
		$sut = $this->sut(null);

		$this->configuration->expects($this->at(0))
			->method('getOptionValue')
			->with(Adi_Configuration_Options::ROLE_EQUIVALENT_GROUPS)
			->willReturn(';ad-group=wp-role;c=d');

		$actual = $sut->getRoleEquivalentGroups();
		$this->assertEquals(2, sizeof($actual));
		$this->assertEquals('d', $actual['c']);
		$this->assertEquals('wp-role', $actual['ad-group']);
	}

	/**
	 * @test
	 */
	public function getMappedWordPressRoles_returnsTheMapping()
	{
		$sut = $this->sut(array('getRoleEquivalentGroups'));

		$sut->expects($this->once())
			->method('getRoleEquivalentGroups')
			->willReturn(array('belonging-security-group'   => 'A', 'not-belonging-security-group' => 'B',
							   'belonging-security-group-2' => 'C'));

		$roleMapping = new Adi_Role_Mapping("username");
		$roleMapping->setSecurityGroups(array('belonging-security-group', 'belonging-security-group-2'));

		$actual = $sut->getMappedWordPressRoles($roleMapping);
		$this->assertEquals(2, sizeof($actual));
		$this->assertEquals('A', $actual[0]);
		$this->assertEquals('C', $actual[1]);
	}

	/**
	 * @test
	 */
	public function roleConstants_haveCorrectValues()
	{
		$this->assertEquals('super admin', Adi_Role_Manager::ROLE_SUPER_ADMIN);
		$this->assertEquals('administrator', Adi_Role_Manager::ROLE_ADMINISTRATOR);
		$this->assertEquals('editor', Adi_Role_Manager::ROLE_EDITOR);
		$this->assertEquals('contributor', Adi_Role_Manager::ROLE_CONTRIBUTOR);
		$this->assertEquals('subscriber', Adi_Role_Manager::ROLE_SUBSCRIBER);
	}

	/**
	 * @test
	 */
	public function updateRoles_handlesSuperAdminRoleDifferent()
	{
		$wpUser = $this->createMock('WP_User');
		$roles = array(Adi_Role_Manager::ROLE_SUPER_ADMIN);

		$sut = $this->sut(array('grantSuperAdminRole'));

		$this->expects($sut, $this->once(), 'grantSuperAdminRole', $wpUser, null);
		$this->expects($wpUser, $this->never(), 'add_role', null, null);

		$sut->updateRoles($wpUser, $roles, false);
	}

	/**
	 * @test
	 */
	public function grantSuperAdminRole_loadsMultisiteFunctions()
	{
		$wpUser = $this->createMock('WP_User');
		$wpUser->ID = 1;

		$sut = $this->sut(array('loadMultisiteFunctions'));

		$sut->expects($this->once())
			->method('loadMultisiteFunctions');

		WP_Mock::wpFunction('grant_super_admin', array(
			'times' => 1,
			'with'  => $wpUser->ID,
		));

		$this->invokeMethod($sut, 'grantSuperAdminRole', array($wpUser));
	}

	/**
	 * @test
	 */
	public function loadMultisiteFunctions_withFunctionAvailable_returns()
	{
		$util = $this->createMock('Core_Util_Internal_Native');

		$util->expects($this->once())
			->method('isFunctionAvailable')
			->with('grant_super_admin')
			->willReturn(true);

		$util->expects($this->never())
			->method('isFileAvailable');

		Core_Util::native($util);

		$sut = $this->sut(null);

		$this->invokeMethod($sut, 'loadMultisiteFunctions');
	}

	/**
	 * @test
	 */
	public function loadMultisiteFunctions_withoutFunctionAvailable_checksForFileAndImportsIt()
	{
		$util = $this->createMock('Core_Util_Internal_Native');

		$util->expects($this->once())
			->method('isFunctionAvailable')
			->with('grant_super_admin')
			->willReturn(false);

		$util->expects($this->once())
			->method('isFileAvailable')
			->willReturn(ABSPATH . 'wp-admin/includes/ms.php')
			->willReturn(true);

		$util->expects($this->once())
			->method('includeOnce')
			->with(ABSPATH . 'wp-admin/includes/ms.php');

		Core_Util::native($util);

		$sut = $this->sut(null);

		$this->invokeMethod($sut, 'loadMultisiteFunctions');
	}

	/**
	 * @test
	 */
	public function getRoles_inSingleSite_removesSuperAdminFromRoles()
	{
		WP_Mock::wpFunction('is_multisite', array(
			'times'  => 1,
			'return' => false,
		));

		$expected = array(
			'administrator' => Adi_Role_Manager::ROLE_ADMINISTRATOR,
			'editor'        => Adi_Role_Manager::ROLE_EDITOR,
			'contributor'   => Adi_Role_Manager::ROLE_CONTRIBUTOR,
			'subscriber'    => Adi_Role_Manager::ROLE_SUBSCRIBER,
		);
		$actual = Adi_Role_Manager::getRoles();

		$this->assertEquals($expected, $actual);
	}

	/**
	 * @test
	 */
	public function getRoles_inMultiSite_containsAllRoles()
	{
		WP_Mock::wpFunction('is_multisite', array(
			'times'  => 1,
			'return' => true,
		));

		$expected = array(
			'super admin'   => Adi_Role_Manager::ROLE_SUPER_ADMIN,
			'administrator' => Adi_Role_Manager::ROLE_ADMINISTRATOR,
			'editor'        => Adi_Role_Manager::ROLE_EDITOR,
			'contributor'   => Adi_Role_Manager::ROLE_CONTRIBUTOR,
			'subscriber'    => Adi_Role_Manager::ROLE_SUBSCRIBER,
		);
		$actual = Adi_Role_Manager::getRoles();

		$this->assertEquals($expected, $actual);
	}
}