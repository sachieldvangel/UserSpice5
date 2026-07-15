<?php
// $menu_override may be a menu ID or a menu_name string (see "Code Usage" in
// the Menu Manager). Most templates gate it with is_numeric() before calling
// new Menu(), so resolve a name to its numeric ID here — one central fix that
// makes names work in every template without touching them. An unknown name
// gets a visible notice and is left as-is, so the template falls back to the
// main menu exactly as it does today.
if (isset($menu_override) && is_string($menu_override) && $menu_override !== '' && !is_numeric($menu_override)) {
  $menuOverrideRow = $db->query("SELECT id FROM us_menus WHERE menu_name = ?", [$menu_override])->first();
  if ($menuOverrideRow) {
    $menu_override = (int)$menuOverrideRow->id;
  } else {
    err('Menu "' . safeReturn($menu_override) . '" was not found, so the default menu was used. Check $menu_override against the Menu Manager.');
  }
}
if($settings->template == "wp"){$ignoreTemplateFix = true;}else{$ignoreTemplateFix = false;}
if(isset($template_override)){
  $template_override = basename($template_override); // strip any path components; prevents traversal in the template paths below
  $settings->template = $template_override;
  if(!file_exists($abs_us_root.$us_url_root."usersc/templates/$template_override/info.xml")){
    die("You have selected a template_override that doesn't exist");
  }
  $ignoreTemplateFix = true;
}
$settings->template = basename($settings->template); // defense-in-depth: a template name must never contain path components
if(file_exists($abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/header.php')){
require_once  $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/header.php';
}else{
  //assume template has been deleted
  if(!$ignoreTemplateFix){
  if(file_exists($abs_us_root . $us_url_root . 'usersc/templates/bs5/header.php')){
    $db->update('settings',1,['template'=>'bs5']);
    $settings->template = "bs5";
    require_once $abs_us_root . $us_url_root . 'usersc/templates/bs5/header.php';
    err("Template missing. Falling back to Standard Template");
  }else{
    // die("Looking for alt");
    $dirs = glob($abs_us_root . $us_url_root . 'usersc/templates/*', GLOB_ONLYDIR);
    $found = 0;
    foreach ($dirs as $d) {
      if($found == 0){
      if(file_exists($d.'/header.php')){
        $template = str_replace($abs_us_root . $us_url_root . 'usersc/templates/', "", $d);
        $db->update('settings',1,['template'=>$template]);
        $settings->template = $template;
        require_once $abs_us_root . $us_url_root . 'usersc/templates/'.$template.'/header.php';
        $template = ucfirst($template);
        err("Template missing. Falling back to $template Template");
        $found = 1;
      }
    }
    }
    if($found == 0){
      die("You do not appear to have a valid template installed");
    }
  }
}//end $ignoreTemplateFix for wordpress compatibility
}
if(!isset($hide_top_navigation) || $hide_top_navigation != true){
  require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/navigation.php'; //custom template nav
}

require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/container_open.php'; //custom template container
if($currentPage != "login.php"){
if(file_exists( $abs_us_root . $us_url_root . 'usersc/includes/system_messages_header.php' ) ){
  require_once $abs_us_root . $us_url_root . 'usersc/includes/system_messages_header.php';
}else{
  require_once $abs_us_root . $us_url_root . 'users/includes/system_messages_header.php';
}
}