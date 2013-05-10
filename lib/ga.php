<?php
/** Google Analytics Legacy PHP Module by Analytics Pros.
 *  Copyright (c) 2013, Analytics Pros
 *  Author: Sam Briesemeister
 * 
 *  This project is free software, distributed under the BSD license. 
 *  Analytics Pros offers consulting and integration services if your firm needs 
 *  assistance in strategy, implementation, or auditing existing work.
 *
 *  (This module is not supported by Google, please don't contact them.)
 *
 *  Version 0.8.7
 *    - Provides cookie manipulation, including browser/client interaction
 *        ... consistent with GA's JS (for __utma, __utmz, __utmb)
 *    - Formulates tracking hits, sends them (optionally) to Google
 *    - Supports pageviews, events, transactions, and custom variables
 *    - Tested extensively with PHP 5.2.6 and 5.3.10
 *    - Supports session continuity via __utmb cookie
 *    - Supports AdWords attribution via utmgclid in __utmz
 *    - Supports non-integer transaction IDs and variable-width currency formats
 *    - Supports automatic transaction ID generation if order_id is null
 *        ... addTrans() must precede addItem(), and the IDs must both be given as null
 *    - DOES NOT support domain hashing on cookies
 *    - DOES NOT apply mobile-mode changes (for IP mapping, etc)
 *
 *
 *  This module attempts to implement interfaces similar to the 
 *  Google Analytics' Javascript library. Full automation (as per
 *  standard usage) interacts with browser/client cookies.
 *
 *  Upcoming features (current issues):
 *    - hit timestamps, not working at the moment
 *    - IP address override, not working at the moment
 *
 *
 *  Standard usage:
 *
 *    // Instantiate a tracker (in full-auto mode)
 *    $tracker = new GoogleAnalyticsTracker('UA-12345-X');
 *   
 *    // Override automatic cookie domain
 *    $tracker->setDominName('example.com');
 *
 *    // Specify the document referrer:
 *    $tracker->setReferrerOverride('http://example.com/');
 *
 *    // Add custom variables
 *    $tracker->setCustomVar(1, 'Page Author', 'Professor Xavier', 1);
 *    $tracker->setCustomVar(2, 'Login Status', 'Logged In', 2);
 *    
 *    $tracker->trackPageview('/this/url', 'page title');
 *
 *    // Track non-pageview events
 *    $tracker->trackEvent('category', 'action', 'label');
 *    $tracker->trackEvent('category', 'action', null, 2, true); // non-interaction with event value
 *   
 *    // Report an e-commerce transaction 
 *    // NOTE: if $order_id is null here, the Transaction ID will be automatically generated based on:
 *    //  date, user's IP & user agent, random number
 *    $tracker->addTrans($order_id, $affiliation, $total, $tax, $shipping, $city, $state, $country);
 *    $tracker->addItem($order_id, $SKU, $name, $category, $price, $quantity);
 *    $tracker->trackTrans();
 *
 *
 *
 *  Additional features:
 *    
 *    // Track hits to multiple accounts:
 *    $tracker->addAccount('UA-98765-X');
 *
 *    // Specify the origin IP
 *    $tracker->setRemoteIP('123.45.67.89');
 *
 *    // Specify the user agent string - browser and OS
 *    // Optional arguments (all are optional):
 *    //  - user agent string (e.g. "Mozilla/5.0...")
 *    //  - user language
 *    //  - character encoding
 *    //  - browser viewport size (i.e. window size)
 *    //  - screen resolution
 *    //  - screen color depth
 *    //  - java enabled (boolean)
 *    //  - flash version
 *    $tracker->setUserAgent("Mozilla/5.0...", 'en-US', 'utf-8', '300x400', '1280x1024', 24, true, '11.4');
 *
 *    // Update cookies (without triggering a new visit)
 *    // NOTE: this is on the TRACKER
 *    $tracker->setSourceInfo('my_source', 'my_campaign_name', 'my_medium');
 *
 *
 *  Advanced (direct) cookie manipulation, using cookies from alternate sources
 *    
 *    $cookies = '__utma=...; __utmz=...'; // custom cookies?
 *    $session = new GoogleAnalyticsSession($cookies); // cookie handler
 *    
 *    // Start a new visit with this visitor's cookies - provide new attribution values
 *    // NOTE: this is on the SESSION
 *    $session->next_visit('my_source', 'my_campaign_name', 'my_medium');
 *    
 *    // Retrieve the updated cookie string:
 *    $cookies = $session->build_cookies();
 *    
 *    // Create a tracker with these cookies...
 *    $tracker = new GoogleAnalyticsTracker('UA-XXXXX-X', $session);
 *
 *
 *    // Note that these features (below) are handled in full-auto mode already
 *    // This description is for manual integration only.
 *
 *    // Create a new tracker, with cookies from the client (HTTP headers)
 *    $tracker = GoogleAnalyticsTracker::readclient('UA-XXXXX-X');
 *
 *    // Send cookies back to the browser, after updating session counters (etc)
 *    // >>> NOTE! This must be called before the response body begins (i.e. before headers_sent())
 *    // Recommend using ob_start() to buffer output
 *    GoogleAnalyticsTracker::pushclient($tracker);
 *
 *    // Create a tracker with cookies from string (or array):
 *    $tracker = GoogleAnalyticsTracker::fromCookies('__utma=...; __utmz=...;', 'UA-XXXXX-X');
 *
 *
 *
 *  (More capabilities are exposed in various classes, but not yet documented.)
 */

if (PHP_VERSION_ID < 520 || !class_exists('DateTimeZone')){

  /* Timezone calculation may be based on
   * http://en.wikipedia.org/wiki/List_of_IANA_time_zones
   * */

  function __timezone_get_seconds($tz){
    switch($tz){
      // TODO: populate more of these
      case 'America/Los_Angeles': return (-1 * 8 * 60 * 60);
    }
    return 0;
  }

  class DateTimeZone {
    public $name = null;
    public $offset_seconds = 0; // seconds from GMT

    public function __construct($name){
      $this->name = $name;
      $this->offset = __timezone_get_seconds($name); 
    }
    public function getName(){
      return $this->name;
    }

    public function getOffset(DateTime $time){
      return ($this->offset) - ($time->timezone->offset_seconds); 
    }

    public static function getGMTOffsetSeconds($time = null){
      if(is_null($time)) $time = strtotime('now');
      return $time - strtotime('now GMT');
    }

    public static function default_timezone($name = null, $tzdefault = 'GMT'){
      if(is_null($name)) $name = @date_default_timezone_get();
      if(empty($name)) $name = $tzdefault;
      date_default_timezone_set($name);
      return self::getGMTOffsetSeconds();
    }
  }


  // Override the default timezone; if not already set, PHP complains
  DateTimeZone::default_timezone();

}

if (PHP_VERSION_ID < 520 || !class_exists('DateTime')) {
  class DateTime {
    public $date; // seconds in GMT (unless timezone is given)
    public $timezone = null;

    const ATOM = "Y-m-d\TH:i:sP";
    const COOKIE = "l, d-M-y H:i:s T";
    const ISO8601 = "Y-m-d\TH:i:sO";
    const RFC822 = "D, d M y H:i:s O";
    const RFC850 = "l, d-M-y H:i:s T";
    const RFC1036 = "D, d M y H:i:s O";
    const RFC1123 = "D, d M Y H:i:s O";
    const RFC2822 = "D, d M Y H:i:s O";
    const RFC3339 = "Y-m-d\TH:i:sP";
    const RSS = "D, d M Y H:i:s O";
    const W3C = "Y-m-d\TH:i:sP";


    public function __construct($date, DateTimeZone $timezone = null) {
      $this->date = strtotime($date);
      $this->timezone = $timezone;
    }

    public function setTimestamp($ts){
      if(is_int($ts)) $this->date = $ts;
    }

    public function setTimeZone(DateTimeZone $timezone) {
      $this->timezone = $timezone;
    }

    public function getOffset(){
      return $this->timezone->offset;
    }

    private function __getDate() {
      return date(self::ATOM, $this->date);    
    }

    public function modify($offset) {
      $this->date = strtotime((string) $offset, $this->date);
    }

    public function format($format) {
      return date($format, $this->date + $this->timezone->offset_seconds);
    }

  }
}


// Base class for CURL integration
class GAURLRequest {
  private $useragent = null;
  private $response = null;
  private $url = null;

  public function __construct($url, $ua = null){
    $this->url = $url;
    $this->useragent = $ua;
  }

  public function set($name, $val){
    $this->{$name} = $val;
  }

  public function response(){
    return self::curl($this->url, $this->useragent);
  }

  // Issue an HTTP request via CURL
  public static function & curl($url, $ua = null){
    $h = curl_init($url);
    curl_setopt($h, CURLOPT_AUTOREFERER, true);
    curl_setopt($h, CURLOPT_NOPROGRESS, true);
    if(is_string($ua))
      curl_setopt($h, CURLOPT_USERAGENT, $ua);
    curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($h, CURLOPT_HEADER, 0);
    $v = curl_exec($h);
    curl_close($h);
    return $v;
  }


  // A simpler parameter joining method
  public static function combine($params, $pair = '=', $sep = '&'){
    $c = count($params);
    return $c ? implode($sep, array_map(
      'sprintf', // NOTE: even built-in functions require names given as strings when mapping 
      array_fill(0, $c, '%s%s%s'), // format string 
      array_keys($params), // keys
      array_fill(0, $c, $pair),  // pairing (=)
      array_values($params) // values
    )) : '';
  }

  // Assemble a URL with query string parameters
  public static function assemble($url, $params){
    $url = sprintf('%s?%s', $url, self::combine($params, '=', '&'));
    return self::cleanURL($url);
  }

  // Assemble a cookie-format string from a parameter array 
  public static function cookie_string($arr){
    return self::combine($arr, '=', '; ');
  }

  public static function cleanURL($str){
    return $str; // DEBUG
    $str = preg_replace('/&[a-z0-9]+=($|&)/', '$2', $str); // empty parameters
    $str = preg_replace('/([\?&])([\?&]+)/', '$1', $str); // duplicate separators
    return preg_replace('/[&]+$/', '', $str);
  }


}

class GoogleAnalyticsSession {
  private $fresh = false;
  // private $cache = array(); // UNUSED

  // UTMA values
  private $domain_hash = null;
  private $unique_id = null;
  private $first_visit = null;
  private $recent_visit = null;
  private $current_visit = null;
  private $visit_count = null;

  // UTMZ values
  private $source_time = null;
  private $source_count = null;
  private $source_name = null;
  private $source_medium = null;
  private $source_campaign = null;
  private $source_terms = null;
  private $source_content = null;
  private $source_gclid = null;
  private $source_campaign_id = null;

  // UTMB values
  private $session_hit_count = 0;
  private $session_last_hit_time = 0;


  public function __construct($cookies = null, $live = true, $fresh = true){
    $this->load_cookies($cookies) || $this->new_visitor($fresh);
    
    // iterate new sessions automatically
    if($live && $this->is_session_expired()) $this->next_visit(); 
  }

  public function __get($name){
    switch($name){
      case 'last_hit': return $this->session_last_hit_time;
      case 'hit_count': return $this->session_hit_count;
      case 'visitor': return $this->build_cookies(1);
      case 'source': return $this->build_cookies(2);
      case 'session': return $this->build_cookies(3);
      case 'cookies': return $this->build_cookies(null);
    }
  }


  public function next_visit($source = null, $campaign = null, $medium = null, $terms = null, $content = null){
    if($this->fresh) return false; // it's already a new visit

    $this->visit_count++;
    $this->recent_visit = $this->current_visit;
    $this->current_visit = time();
    $this->session_last_hit_time = $this->current_visit;
    $this->session_hit_count = 0;

    if($campaign || $medium || $source){
      $this->source_count++;
      $this->source_time = $this->current_visit;
      $this->set_source($campaign, $source, $medium, $terms, $content);
    }

    return true;
  }

  public function next_hit($timestamp = null){
    $this->session_hit_count++;
    $this->session_last_hit_time = is_null($timestamp) ? time() : $timestamp;
  }

  public function set_source($campaign = null, $source = null, $medium = null, $terms = null, $content = null, $gclid = null){
    if($campaign || $medium || $source){
      $this->source_count++;
      $this->source_campaign = $campaign;
      $this->source_name = $source;
      $this->source_medium = $medium;
      $this->source_terms = $terms;
      $this->source_content = $content;
      $this->source_gclid = $gclid;
    }
  }

  public function is_session_expired(){
    // Was the last hit within the last thirty minutes?
    $last_thirty_min = time() - (30 * 60);
    return ($this->session_last_hit_time) < $last_thirty_min;
  }

  public function get_visitor_id(){
    return $this->unique_id;
  }


  // Generate valid cookies for a fresh visitor
  private function new_visitor($fresh = true){
    $this->fresh = $fresh;
    $this->domain_hash = (empty($this->domain_hash) ? 1 : $this->domain_hash);
    $this->unique_id = abs(rand(1, 50) * rand(100, 8000) * rand(100, 300));
    $this->first_visit = time();
    $this->recent_visit = $this->first_visit;
    $this->current_visit = $this->first_visit;
    $this->visit_count = 1;
    $this->source_time = $this->first_visit;
    $this->source_count = 1;
    $this->source_name = '(direct)';
    $this->source_campaign = '(direct)';
    $this->source_medium = '(none)';
    $this->source_gclid = null;
    $this->session_last_hit_time = time();
    $this->session_hit_count = 0;
    return true;
  }

  // Parse and map cookie values
  private function load_cookies($cookies){
    if(is_string($cookies)) $cookies = self::parse_cookies($cookies);

    // Fail if this doesn't look like a valid cookie store.
    if(!(is_array($cookies) || is_object($cookies)) || !array_key_exists('__utma', $cookies) || !array_key_exists('__utmz', $cookies))
      return false;

    // Start assuming the session is current, if session cookies are present
    if(array_key_exists('__utmb', $cookies)){
      $session = self::dotfields($cookies['__utmb'], 4); 
      $this->session_hit_count = $session[1];
      $this->session_last_hit_time = $session[3];
    } elseif(array_key_exists('__utmc', $cookies)){
      $this->session_last_hit_time = time();
    } elseif(array_key_exists('last_hit', $cookies)){
      $this->session_last_hit_time = (int) $cookies['last_hit'];
    }


    // Split into fields
    $visitor = self::dotfields($cookies['__utma'], 6);
    $source = self::dotfields($cookies['__utmz'], 5);

    // Assign properties
    $this->domain_hash = (int) $visitor[0];
    $this->unique_id = (int) $visitor[1];
    $this->first_visit = (int) $visitor[2];
    $this->recent_visit = (int) $visitor[3];
    $this->current_visit = (int) $visitor[4];
    $this->visit_count = (int) $visitor[5];
    $this->source_time = (int) $source[1];
    $this->source_count = (int) $source[3];
    
    // Load source attributes
    $c = preg_match_all('/utm([a-z]+)=([^\|]+)[\|]?/i', $source[4], $m);
    for($i = 0; $i < $c; $i++){
      switch($m[1][$i]){
      case 'cid': $this->source_campaign_id = $m[2][$i]; break;
      case 'cmd': $this->source_medium = $m[2][$i]; break;
      case 'csr': $this->source_name = $m[2][$i]; break;
      case 'ccn': $this->source_campaign = $m[2][$i]; break;
      case 'ctr': $this->source_terms = $m[2][$i]; break;
      case 'cct': $this->source_content = $m[2][$i]; break;
      case 'gclid': $this->source_gclid = $m[2][$i]; break;
      }
    }

    return true;

  }

  // Combine cookie elements into the proper format
  public function build_cookies($s = null){
    switch($s){
    case 1: $s = '%1$d.%2$d.%3$d.%4$d.%5$d.%6$d'; break; // utma
    case 2: $s = '%1$d.%7$d.%6$d.%8$d.%9$s'; break; // utmz
    case 3: $s = '%1$d.%11$d.10.%10$d'; break; // utmb
    case 4: $s = '%1$d'; break; // utmc
    default:
      $s = (is_string($s) ? $s : '__utma=%1$d.%2$d.%3$d.%4$d.%5$d.%6$d; __utmz=%1$d.%7$d.%6$d.%8$d.%9$s; __utmb=%1$d.%11$d.10.%10$d; __utmc=%1$d');
    }

    return sprintf($s,
      $this->domain_hash, 
      $this->unique_id,
      $this->first_visit, 
      $this->recent_visit, 
      $this->current_visit,
      $this->visit_count, // #6
      $this->source_time, 
      $this->source_count,
      self::assemble_source(array(
        'utmgclid' => $this->source_gclid,
        'utmcid' => $this->source_campaign_id,
        'utmccn' => $this->source_campaign,
        'utmcsr' => $this->source_name,
        'utmcmd' => $this->source_medium,
        'utmctr' => $this->source_terms,
        'utmcct' => $this->source_content
      )),
      $this->session_last_hit_time,
      $this->session_hit_count
    );
  }

  // combine source attributes for utmz
  public static function assemble_source($arr){
    return GAURLRequest::combine(array_filter($arr), '=', '|');
  }

  // split cookie fields
  public static function dotfields($string, $count = 5){
    return explode('.', $string, $count);
  }


  // Produce an array of cookie values from a cookie-format string
  public static function parse_cookies($st){
    $result = array();
    $c = preg_match_all('/\s*([^=]+)=([^;]*)[;]?/', $st, $m, PREG_PATTERN_ORDER);
    for($i = 0; $i < $c; $i++){
      $result[$m[1][$i]] = $m[2][$i];
    }
    return $result;
  }


}

// Compile the |utme| parameter in Google's X10 format
class GoogleAnalyticsEventData {
  private $non_interaction = null;
  private $eventdata = array();
  private $var_names = array(); // cv
  private $var_values = array(); // cv
  private $var_scopes = array(); // cv

  public function __construct(){
    // nothing
  }


  // Clear event data (and vars, if specified)
  public function reset($varstoo = false){
    $this->eventdata = array();
    $this->eventvalue = null;
    $this->non_interaction = false;
    if($varstoo){
      $this->var_names = array();
      $this->var_values = array();
      $this->var_scopes = array();
    }
  }

  public function setEvent($category, $action, $label = null, $value = null, $non_interaction = false){
    $this->eventdata = array_filter(array($category, $action, $label));
    $this->eventvalue = $value;
    $this->non_interaction = $non_interaction;
  }

  public function setCustomVar($slot, $name, $value, $scope){
    $this->var_names[$slot] = $name;
    $this->var_values[$slot] = $value;
    $this->var_scopes[$slot] = $scope;
  }

  public function hasCustomVars($numvars = 5){
    for($i = 0; $i < $numvars; $i++){
      if(!empty($this->var_names[$i])) return true;
    }
    return false;
  }

  public static function assemble($o, $with_event = true){
    $utme = array();

    if($with_event && is_array($o->eventdata) && count($o->eventdata) > 0)
      array_push($utme, self::ujoin('5', $o->eventdata, true));

    if($with_event && $o->eventvalue)
      array_push($utme, '(', (int) $o->eventvalue, ')');

    if($o->hasCustomVars()){
      array_push($utme,
        self::uoffset('8', array_filter($o->var_names), true),
        self::uoffset('9', array_filter($o->var_values), true),
        self::uoffset('11', array_filter($o->var_scopes), true)
      );
    }

    return implode('', $utme);
  }


  public static function interactive($o){
    return $o->non_interaction ? '1' : '';
  }

  // Apply replacements to match Google's "X10" encoding.
  public static function x10_escape($str){
    return str_replace(array("'", ')', '*', '!'), array("'0", "'1", "'2", "'3"), $str);
  }

  // Combine elements in a field
  private static function ujoin($index, $param, $encode = false){
    if(!is_array($param)) return '';
    if($encode) $param = array_map('urlencode', self::x10_escape($param));
    return $index . '(' . implode('*', array_filter($param)) . ')';
  }

  // Combine values from columnar data (custom vars) in multiple fields
  private static function uoffset($index, $values, $encode = false){
    $result = array(); $x = 1; $y = false;
    foreach($values as $k => $v){
      $v = ($encode ? rawurlencode(self::x10_escape($v)) : $v);
      array_push($result, (($k == $x) ? '' : ($k . '!')) . ($v));
      $x = $k + 1;
    }
    return self::ujoin($index, $result, false);
  }

}

// Base Class for Google Analytics hits
class GoogleAnalyticsBeacon extends GAURLRequest {
  const maxint = 1073741824;
  const version = '4.8.1ma';
  
  public static $gifpath = 'http://www.google-analytics.com/__utm.gif';

  protected $use_event = false;
  protected $eventdata = null;
  protected $referrer = '-';
  protected $hostname = '-';
  protected $hit_time = null;
  protected $client_ip = null;


  public function __construct(){
    parent::__construct(self::$gifpath);
  }

  public function setEventData($o = null){
    if($o === null || $o instanceOf GoogleAnalyticsEventData) $this->eventdata = $o;
  }

  public function setIPAddress($ip, $anon = false){
    if($ip === true) $ip = self::get_client_ip();
    $this->client_ip = $anon ? self::clean_ip($ip) : $ip;
  }

  public function setHitTimestamp($s){
    $this->hit_time = $s;
  }

  public static function getEventParameters($o, $event = false){
    if($o instanceOf GoogleAnalyticsEventData){
      return array(GoogleAnalyticsEventData::assemble($o, $event), GoogleAnalyticsEventData::interactive($o));
    } else return array('', '');
  }

  public function compile($account, $utma, $utmz, $counter){
    return self::buildBeacon($this, $account, $utma, $utmz, $counter);
  }

  public static function param_utmcc($utma, $utmz){
    return sprintf('__utma%%3D%s%%3B%%2B__utmz%%3D%s%%3B', $utma, $utmz);
  }


  // Prepare the full query string for the beacon
  public static function buildBeaconQuery($prelim, $finally, $special, 
    $account, $utma, $utmz, $x10 = null, $with_event = false, 
    $hostname = '-', $referrer = '-', $counter = 0, $ip_address = '', $hit_time = ''
  ){
    list($utme, $utmni) = self::getEventParameters($x10, $with_event);
    return implode('&', array(
      sprintf($prelim, 
        self::version, 
        abs(rand(10, self::maxint)), 
        $hostname, 
        $utme,
        $utmni
      ),
      // NOTE: sprintf is given as string, since call_user_func_array requires function identifier (as string)
      $special ? call_user_func_array('sprintf', $special) : '',
      sprintf($finally,
        $referrer,
        $account,
        self::param_utmcc($utma, $utmz), 
        $counter,
        $ip_address,
        $hit_time
      )
    ));
  }

  public static function buildBeacon($hit, $acct, $utma, $utmz, $counter){
    list($pre, $fin, $spec) = $hit->attributes();
    return self::cleanURL(self::$gifpath . '?' . self::buildBeaconQuery(
      $pre, $fin, $spec, $acct, $utma, $utmz, $hit->eventdata, 
      $hit->use_event, $hit->hostname, $hit->referrer, $counter,
      $hit->client_ip, $hit->hit_time
    ));
  }


  public static function get_client_ip($proxy = true){
    $fwd = $proxy ? @getenv('HTTP_X_FORWARDED_FOR') : null;
    if($fwd){
      $fwd = explode(',', $fwd);
      $fwd = $fwd[ count($fwd) - 1 ];
    }  
    return $fwd ? $fwd : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
  }

  // Strip last octet from IP address 
  public static function clean_ip($ipaddr){
    return preg_replace('/\.[0-9]+$/', '.0', $ipaddr);
  }



}

class GoogleAnalyticsPageview extends GoogleAnalyticsBeacon {
  protected $pageview = '-';
  protected $pagetitle = '(none)';
  protected $encoding = 'UTF-8';
  protected $viewport = '-';
  protected $screenres = '-';
  protected $colordepth = '-';
  protected $javaenabled = '-';
  protected $flashversion = '-';
  protected $language = 'en-us';

  public function __construct($path, $title = null){
    parent::__construct();
    $this->pageview = $path;
    if($title) $this->pagetitle = $title;
    $this->hit_id = abs(rand(5, self::maxint)); 
  }

  public function setBrowserDetails($language = null, $encoding = null, $viewport = null, $screenres = null, $colordepth = null, $java = null, $flash = null){
    $this->browser_set = true;
    $this->language = ($language ? $language : '-');
    $this->encoding = ($encoding ? $encoding : '-');
    $this->viewport = ($viewport ? $viewport : '-');
    $this->screenres = ($screenres ? $screenres : '-');
    $this->colordepth = ($colordepth ? $colordepth : 0);
    $this->javaenabled = ($java ? $java : 0);
    $this->flashversion = ($flash ? $flash : 0);
  }

  protected function attributes(){
    return self::buildPageview($this);
  }

  public static function buildPageview($pv){
    return array(
      'utmwv=%1$s&utmn=%2$d&utmhn=%3$s&utme=%4$s&utmni=%5$s', // preliminary
      'utmr=%1$s&utmac=%2$s&utmcc=%3$s&utms=%4$d&utmht=%6$s', // common &utmip=%5$s
      array( // pageview parameters
        'utmp=%1$s&utmdt=%2$s&utmcs=%3$s&utmsr=%4$s&utmvp=%5$s&utmsc=%6$d&utmul=%7$s&utmje=%8$d&utmfl=%9$s',
        rawurlencode($pv->pageview), 
        rawurlencode($pv->pagetitle),
        rawurlencode($pv->encoding),
        rawurlencode($pv->screenres),
        rawurlencode($pv->viewport),
        rawurlencode($pv->colordepth),
        rawurlencode($pv->language),
        rawurlencode($pv->javaenabled ? 1 : 0),
        rawurlencode($pv->flashversion ? $pv->flashversion : 0)
      )
    );  
  }

}

class GoogleAnalyticsEvent extends GoogleAnalyticsPageview {
  protected $use_event = true;

  public function __construct($path, $event){
    parent::__construct($path);
    $this->setEventData($event);
  } 

  // This class is a very slight modification of the Pageview
  protected function attributes(){
    list($p, $c, $s) = self::buildPageview($this);
    $p = sprintf('utmt=event&%s', $p);
    return array($p, $c, $s);
  }

}

class GoogleAnalyticsTransaction extends GoogleAnalyticsBeacon {
  public static $currency_format = '1.2f';
  public $order_id = null;
  public $affiliation = null;
  public $total = null;
  public $tax = null;
  public $shipping = null;
  public $city = null;
  public $state = null;
  public $country = null;

  public function __construct($order_id = null, $affiliation = null, $total = 0, $tax = 0, $shipping = 0, $city = null, $state = null, $country = null){
    if(is_null($order_id)){
      $order_id = self::generate_transaction_id();
    }
    $this->order_id = $order_id;
    $this->affiliation = $affiliation;
    $this->total = $total;
    $this->tax = $tax;
    $this->shipping = $shipping;
    $this->city = $city;
    $this->state = $state;
    $this->country = $country;
  }

  protected function attributes(){
    return self::buildTransaction($this);
  }

  public static function buildTransaction($tran){
    return array(
      'utmwv=%1$s&utmn=%2$d&utmt=tran&utmhn=%3$s', // preliminary
      'utmac=%2$s&utmcc=%3$s&utms=%4$d&utmht=%6$s', // common &utmip=%5$s
      array(
        'utmtid=%s&utmtst=%s&utmtto=%' . self::$currency_format . '&utmttx=%' . self::$currency_format . '&utmtsp=%' . self::$currency_format . '&utmtci=%s&utmtrg=%s&utmtco=%s',
        rawurlencode($tran->order_id),
        rawurlencode($tran->affiliation),
        ($tran->total), // float
        ($tran->tax), // float
        ($tran->shipping), // float
        rawurlencode($tran->city),
        rawurlencode($tran->state),
        rawurlencode($tran->country)
      )
    );
  }

  public static function stringhash_eleven($str){
    for($v = 0, $i = 0; $i < strlen($str); $i++){
      $v = ((($v << 5) - $v + ord(substr($str, $i, 1))) & 0xFFFFFFFFF) | ($v >> 32);
    }
    return sprintf('%11.11s', sprintf('%011u', $v));
  }


  public static function generate_transaction_id(DateTime $timestamp = null, $ip_addr = null, $user_agent = null){
    if(is_null($timestamp)){
      $timestamp = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    }
    if(is_null($ip_addr)){
      $ip_addr = $_SERVER['REMOTE_ADDR'];
    }
    if(is_null($user_agent)){
      $user_agent = $_SERVER['HTTP_USER_AGENT'];
    }
    return sprintf('%s-%s-%05u', 
      $timestamp->format('Y-m-d'), 
      self::stringhash_eleven($ip_addr . $user_agent),
      mt_rand(0, 99999)
    );
  }


}

class GoogleAnalyticsTransactionItem extends GoogleAnalyticsBeacon {
  private $currency_format = null;
  public $order_id = null;
  public $sku = null;
  public $name = null;
  public $category = null;
  public $price = null;
  public $quanity = 0;

  public function __construct($order_id, $sku = null, $name = null, $category = null, $price = 0, $quantity = 1){
    $this->order_id = $order_id;
    $this->sku = $sku;
    $this->name = $name;
    $this->category = $category;
    $this->price = $price;
    $this->quantity = $quantity;
  }

  protected function attributes(){
    return self::buildTransactionItem($this);
  }

  public static function buildTransactionItem($item){
    $currency_format = GoogleAnalyticsTransaction::$currency_format;
    return array(
      'utmt=item&utmwv=%1$s&utmn=%2$d&utmhn=%3$s', // preliminary
      'utmac=%2$s&utmcc=%3$s&utms=%4$d&utmht=%6$s', // common &utmip=%5$s
      array(
        'utmtid=%s&utmipc=%s&utmipn=%s&utmiva=%s&utmipr=%' . $currency_format . '&utmiqt=%d',
        rawurlencode($item->order_id),
        rawurlencode($item->sku),
        rawurlencode($item->name),
        rawurlencode($item->category),
        ($item->price), // float
        ($item->quantity) // integer
      )
    );
  }

}


class GoogleAnalyticsAccount {
  const use_mobile_mode = false;
  
  private $counter = 0;
  private $account = 'UA-XXXXX-X';

  public static $accounts = array();


  public function __construct($ac, $mobile = false){
    $this->account = $ac;
  }

  public function render($hit, $utma, $utmz, $session = null, $ip = '-'){
    $this->counter++;
    if($session){
      $session->next_hit();
      $this->counter = $session->hit_count;
    }
    $url = $hit->compile($this->account, $utma, $utmz, $this->counter); 
    if(self::use_mobile_mode && $ip && $ip != '-')
      $url = self::adapt_mobile($url, $ip, $session->get_visitor_id());
    return $url;
  }


  // Retrieve an account object from the pool (created if necessary)
  public static function get($ua){
    if(!array_key_exists($ua, self::$accounts))
      self::$accounts[$ua] = new GoogleAnalyticsAccount($ua);
    return self::$accounts[$ua];
  }

  /* Adjust the beacon's query parameters to fit Mobile-tracking mode
   * XXX: this part has some issues.
   *  - It doesn't seem to map IDs correctly in mobile mode
   *  - It's not correctly integrating the IP address for geo-resolution
   */
  public static function adapt_mobile($url, $ip_addr = 0, $unique_id = 0){
    $url = str_replace('utmac=UA-', 'utmac=MO-', $url);

    if($ip_addr){
      $url = preg_replace(array('/utmip=[^&]+/'), array('utmip=' . $ip_addr), $url);
    }

    if($unique_id){
      $url = preg_replace(array('/utmcc=[^&]+/'), array('utmcc=__utma%3D999.999.999.999.999.1%3B'), $url);
      $url = $url . '&utmvid=' . $unique_id;
    }
    return $url; 
  }



}



class GoogleAnalyticsTracker {
  public static $send = true; // whether to issue the CURL calls on beacons
  public static $domain_scope = 2; // how many domain steps to keep on cookies
  
  public $beacons = array();  // hits we've already sent
  
  private $accounts = array();
  private $queue = array(); // hits we're preparing to send
  private $event = null;
  private $session = null;
  private $referrer = '-';
  private $hostname = '-';
  private $last_transaction = null; // a reference for transaction caching, for automatic IDs

  private $timestamp = null;
  private $client_ip = '-';
  
  private $full_auto = false;
  private $send_cookies = true;
  
  public $cookie_domain = null;
  public $useragent = null;
  public $browser_details = array( // casting to object here fails on 5.3.10 on my mac.
    'language' => null,
    'encoding' => null,
    'viewport' => null,
    'screenres' => null,
    'colordepth' => null,
    'javaenabled' => null,
    'flashversion' => null
  );

  public $current_page = null;

  public static function get_server_var($name, $default = null){
    if(isset($_SERVER[$name])) return $_SERVER[$name];
    return $default;
  }

  // Constructor
  public function __construct($account = null, $session = null, $auto = true){
    $this->addAccount($account);

    $this->browser_details = (object) $this->browser_details; // cast to object

    // Spawn a new session as needed if running in "auto" mode
    if($session === null && $auto === true){
      $session = new GoogleAnalyticsSession($_COOKIE);
      $this->referrer = self::get_server_var('HTTP_REFERER');
      $this->hostname = self::get_server_var('SERVER_NAME');
      $this->setRemoteIP(self::get_server_var('REMOTE_ADDR'));
      $this->setUserAgent(self::get_server_var('HTTP_USER_AGENT'));
      $this->full_auto = true;
    }
    
    // Cache the session's cookies
    if($session instanceOf GoogleAnalyticsSession){
      $this->session = $session;
    }

    // Set initial cookie domain 
    $this->setDomainName(self::$domain_scope, false);

  }

  // A few dynamic properties...
  public function __get($name){
    switch($name){
      case 'utm_visitor': return $this->session->visitor;
      case 'utm_campaign': return $this->session->source;
      case 'utm_session': return $this->session->session;
      case 'cookies': return $this->session->cookies;
    }
  }

  public function setHitTimestamp($x = null){
    $this->timestamp = sprintf('%.0f', self::get_milliseconds($x));
    # printf("# setting hit timestamp: %s (%s, %s)\n", $this->timestamp, gettype($this->timestamp), $x);
  }

  public function setUserAgent($str = null, $language = null, $encoding = null, $viewport = null, $screenres = null, $colordepth = null, $java = null, $flash = null){
    if(!is_null($str)) $this->useragent = $str;
    $this->browser_details->language = $language;
    $this->browser_details->encoding = $encoding;
    $this->browser_details->viewport = $viewport;
    $this->browser_details->screenres = $screenres;
    $this->browser_details->colordepth = $colordepth;
    $this->browser_details->javaenabled = $java;
    $this->browser_details->flashversion = $flash;
  }

  public function setRemoteIP($ipaddr, $anon = false){
    $this->client_ip = $anon ? GoogleAnalyticsBeacon::clean_ip($ipaddr) : $ipaddr;
  }

  public function setDomainName($d, $hostname_too = true){
    $this->cookie_domain = self::get_cookie_domain($d);
    if($hostname_too) $this->hostname = $this->cookie_domain;
  }

  public function setReferrerOverride($s){
    $this->referrer = $s;
  }

  // Create a tracker object, add it to the filter stack
  public function addAccount($ua){
    if(is_string($ua)) array_push($this->accounts, GoogleAnalyticsAccount::get($ua));
  }

  // Override source/campaign information on the cookies
  public function setSourceInfo($source, $campaign = null, $medium = null, $terms = null, $content = null){
    $this->session->set_source($campaign, $source, $medium, $terms, $content);
  }

  // Add a custom variable to the next hit(s)
  public function setCustomVar($slot, $name, $value, $scope = 2){
    $this->get_event()->setCustomVar($slot, $name, $value, $scope);
  }

  // Process an event (interaction) hit
  public function trackEvent($category, $action, $label = null, $value = 0, $non_interaction = false, $path = null){
    $this->get_event()->setEvent($category, $action, $label, $value, $non_interaction);
    $e = new GoogleAnalyticsEvent(self::def($path, $this->current_page), $this->get_event());
    $e->setBrowserDetails(
      $this->browser_details->language,
      $this->browser_details->encoding,
      $this->browser_details->viewport,
      $this->browser_details->screenres,
      $this->browser_details->colordepth,
      $this->browser_details->javaenabled,
      $this->browser_details->flashversion
    );
    $this->enqueue($e);
    $this->get_event()->reset(); // events occur once
    $this->run_queue();
  }

  // Process a pageview hit
  public function trackPageview($url, $title = null){
    if(is_null($this->current_page)){
      $this->current_page = $url;
    }
    $p = new GoogleAnalyticsPageview($url, $title);
    $p->setBrowserDetails(
      $this->browser_details->language,
      $this->browser_details->encoding,
      $this->browser_details->viewport,
      $this->browser_details->screenres,
      $this->browser_details->colordepth,
      $this->browser_details->javaenabled,
      $this->browser_details->flashversion
    );
    $this->enqueue($p);
    $this->run_queue(); 
  }

  // Enqueue an item hit
  public function addItem($order, $sku, $name, $category, $price, $quantity = 1){
    if(is_null($order) && $this->last_transaction instanceof GoogleAnalyticsTransaction){
      $order = $this->last_transaction->order_id;
    }
    $this->enqueue(new GoogleAnalyticsTransactionItem(
      $order, $sku, $name, $category, $price, $quantity
    ));
  }

  // Enqueue a transaction hit
  public function addTrans($order, $affiliation, $total, $tax = 0, $shipping = 0, $city = null, $state = null, $country = null){
    $this->last_transaction = new GoogleAnalyticsTransaction(
      $order, $affiliation, $total, $tax, $shipping, $city, $state, $country
    );
    $this->enqueue($this->last_transaction); 
  }

  // Push transaction hits
  public function trackTrans(){
    $this->run_queue(); 
  }

  // Shortcut for creating the event if it doesn't already exist
  private function get_event(){
    if(!$this->event) $this->event = new GoogleAnalyticsEventData();
    return $this->event;
  }

  // Add a hit to the queue - passing it through tracker filters, adding cookies, etc.
  private function enqueue($beacon){
    if($beacon instanceOf GoogleAnalyticsBeacon){
      $t = $this->timestamp 
        ? $this->timestamp 
        : self::get_milliseconds(time());

      // pass tracking attributes to beacon compiler
      $beacon->setEventData($this->event);
      $beacon->set('hostname', $this->hostname);
      $beacon->set('referrer', $this->referrer);
      $beacon->setHitTimestamp($t);
      $beacon->setIPAddress($this->client_ip);

      $utma = self::filter_cookie($this->utm_visitor);
      $utmz = self::filter_cookie($this->utm_campaign);

      for($i = 0; $i < count($this->accounts); $i++){
        array_push($this->queue,
          $this->accounts[$i]->render($beacon, $utma, $utmz, $this->session, $this->client_ip)
        );
      }
    }
  }

  // Process all hits in the queue
  private function run_queue($clear = true){
    $q = $this->queue;
    if($clear) $this->queue = array();
    self::batch_request($q, $this->useragent);
    $this->beacons = array_merge($this->beacons, $q);
    // Send updated cookies back
    if($this->full_auto && !headers_sent() && $this->send_cookies){
      $this->send_cookies = false;
      self::pushclient($this);
    }
    return $q;
  }

  // Push requests to Google (gif hits)
  public static function batch_request($urls, $useragent = null){
    for($i = 0; $i < count($urls); $i++){
      if(self::$send) GAURLRequest::curl($urls[$i], $useragent);
    }
  }


  // Parse domain for scope (or override)
  public static function get_cookie_domain($d = 2, $server_name = null){
    $cookie_domain = 0;
    $server_name = (is_string($server_name) ? $server_name : $_SERVER['SERVER_NAME']);
    if(is_numeric($d)){
      $c = preg_match('/((?:[a-z0-9\-]+[\.]?){' . $d . '})$/i', $server_name, $match);
      $cookie_domain = $match[1];
    } elseif(is_string($d))
      $cookie_domain = $d;
    return $cookie_domain;
  }

  // TODO: validate that this is getting correct values when passed microtime()
  public static function get_milliseconds($t){
    if(is_string($t)) {
      list($u, $s) = explode(' ', $t);
      return sprintf('%d%03d', $s, $u * 1000);
    } elseif(is_int($t)) {
      return $t * 1000;
    }
  }

  // Load cookies directly
  public static function fromCookies($cookies, $account = null){
    if(is_string($cookies)) $cookies = GoogleAnalyticsSession::parse_cookies($cookies); 
    return new GoogleAnalyticsTracker($account, new GoogleAnalyticsSession($cookies), false);
  }
  
  public static function def($value, $default){
    return (empty($value) || is_null($value) || $value == '-') ? $default : $value;
  }

  // Pull cookies from the client's request headers
  // to emulate standard GA javascript behavior 
  public static function readclient($account = null, $c = null){
    if(! is_array($c)) $c = $_COOKIE;
    $tracker = self::fromCookies($c, $account);
    return $tracker;
  }


  // Send updated cookies back to the client
  public static function pushclient(& $tracker, $server_name = null, $ret = false){
    $cookie_domain = is_string($server_name) ? $server_name : $tracker->cookie_domain;  // tracker-specific
    $two_years = (24 * 365 * 2 * 60 * 60) + time();
    $six_months = (24 * 6 * 30 * 60 * 60) + time();
    $thirty_min = (30 * 60) + time();
    
    $cookies = array(
      '__utma' => array(self::filter_cookie($tracker->utm_visitor), $two_years, '/', $cookie_domain),
      '__utmz' => array(self::filter_cookie($tracker->utm_campaign), $two_years, '/', $cookie_domain),
      '__utmb' => array(self::filter_cookie($tracker->utm_session), $thirty_min, '/', $cookie_domain)
    );

    if($ret) return $cookies;
    else {
      foreach($cookies as $i => $c){
        setrawcookie($i, $c[0], $c[1], $c[2], $c[3]);
      }
    }
  }


  // Encode strings for (raw) cookie values
  private static $bad_cookie_chars = ",; \t\r\n\013\014"; 
  public static function filter_cookie($v){
    $x = str_split(self::$bad_cookie_chars);
    return str_replace($x, array_map('rawurlencode', $x), $v);
  }

}




?>
