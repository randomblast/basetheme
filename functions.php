<?
	// This file must be included for Themebase to work properly
	require_once('.build/generated.php');

	register_sidebar(array(
		  'name' => 'Header'
		, 'id' => 'sidebar-header'
	));
	register_sidebar(array(
		  'name' => 'Footer'
		, 'id' => 'sidebar-footer'
	));
?>
