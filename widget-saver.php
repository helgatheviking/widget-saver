<?php
/*
Plugin Name: Widget Saver
Plugin URI: http://www.phenomenoodle.com/free-resources
Description: Saves widget layouts so that they can be restored at a later date.
Version: 2.0.0
Author: Zorgbargle | Phenomenoodle
Author URI: http://www.phenomenoodle.com
*/

// Implementation note: this plugin attempts to minimise name-space polution by not declaring any global variables or
// functions, try to keep it that way!

// prevent direct calls to this file from doing anything.
if(!defined('ABSPATH') || !defined('WPINC'))
{
	die();
}

// If anything else clashes with our main classes, report a warning on the admin plugin panel, and do nothing else. 
if (class_exists('PNDL_WidgetSaverPlugin', false))
{
	if (is_admin())
	{
		// add action using create_function to avoid adding a global function.
		add_action("after_plugin_row_".basename(dirname(__FILE__)) . "/" . basename(__FILE__), 
			create_function('', 'echo "<tr><td /><td /><td><strong>'.__('Warning').':</strong> '. __( 'There is a name-clash with another plugin. This plugin will not function until the name clash has been resolved.', 'widget-saver' ).'<td></tr>";'));
	}
	return;
}
// No clash, so we can launch the plugin.
else
{
	// import the file containing the plugin class definition
	require_once 'include/widgetsaver_plugin.php';

	// create and register plugin using static method on the plugin class, this avoids potential name clashes with 
	// variable names/function names
	PNDL_WidgetSaverPlugin::create(__FILE__);
}
?>