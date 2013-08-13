<?php
/**
 * RevisionsDue Plugin: Find pages that should be revised as specified by the <revision_frequency>YYY</revision_frequency> xml.
 * syntax ~~REVISIONS:<choice>~~  <choice> :: all
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     <Stephan@SparklingSoftware.com.au>
 * @author     Stephan Dekker <Stephan@SparklingSoftware.com.au>
 */
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
 
require_once(DOKU_INC.'inc/search.php');
 
define('DEBUG', 0);
 
function revision_callback_search_wanted(&$data,$base,$file,$type,$lvl,$opts) {
    global $conf;

	if($type == 'd'){
		return true; // recurse all directories, but we don't store namespaces
	}
 
    if(!preg_match("/.*\.txt$/", $file)) {  // Ignore everything but TXT
		return true;
	}
 
	// get id of this file
	$id = pathID($file);
 
	//check ACL
	if(auth_quickaclcheck($id) < AUTH_READ) {
		return false;
	}

    $revision_frequency = get_revision_frequency($file);
 
    // try to avoid making duplicate entries for forms and pages
	$item = &$data["$id"];
	if(! isset($item)) {
       // Create a new entry
       $filename = $conf['datadir'].$file;
       $last_modified = filemtime($filename);
       
       $revision_date = $last_modified + (intval($revision_frequency) * 86400);       
	   if ($revision_date < time()) {
           $data["$id"]=array('revision' => $last_modified, 
                'frequency' => $revision_frequency, 
                'revision_date' => $revision_date );
       }      
       else
       {
           // The item is actually already added to the array! We need to remove it if we don't need it.
           unset($data["$id"]);
       }
	}
  
	return true;
}

function revision_string($revision) {

  $result = date("Y-m-d", $revision);
  return $result;
}

function get_revision_frequency($file) {
    global $conf;

  $filename = $conf['datadir'].$file;
  $body = @file_get_contents($filename);
  $pattern = '/<revision_frequency>\-?\d+<\/revision_frequency>/i';
  $count = preg_match($pattern, $body, $matches);
  $result = htmlspecialchars($matches[0]);    
  $result = str_replace(htmlspecialchars('<revision_frequency>'), "", $result);
  $result = str_replace(htmlspecialchars('</revision_frequency>'), "", $result);
  
  return $result;
}

function date_compare($a, $b) {
    if ($a['revision_date'] == $b['revision_date']) {
       return 0;
    }
    return ($a['revision_date'] < $b['revision_date']) ? -1 : 1;
}


/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_revisionsdue extends DokuWiki_Syntax_Plugin {
    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Stephan Dekker',
            'email'  => 'Stephan@SparklingSoftware.com.au',
            'date'   => @file_get_contents(dirname(__FILE__) . '/VERSION'),
            'name'   => 'RevisionsDue Plugin',
            'desc'   => 'Find pages that should be revised as specified by the <revision_frequency>YYY</revision_frequency> xml.
            syntax ~~REVISIONS:<choice>~~ .
            <choice> :: all',
            'url'    => 'http://dokuwiki.org/plugin:revisionsdue',
        );
    }
 
    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }
 
    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }
 
    /**
     * Where to sort in?
     */
    function getSort(){
        return 990;     //was 990
    }
 
 
    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~REVISIONS:[0-9a-zA-Z_:!]+~~',$mode,'plugin_revisionsdue');
    }
 
    /**
     * Handle the match
     */
 
    function handle($match, $state, $pos, &$handler){
        $match_array = array();
        $match = substr($match,12,-2); //strip ~~REVISIONS: from start and ~~ from end
        // Wolfgang 2007-08-29 suggests commenting out the next line
        // $match = strtolower($match);
        //create array, using ! as separator
        $match_array = explode("!", $match);
        // $match_array[0] will be all, or syntax error
        // this return value appears in render() as the $data param there
        return $match_array;
    }
 
    /**
     * Create output
     */
    function render($format, &$renderer, $data) {
        global $INFO, $conf;

        if($format == 'xhtml'){
 
			// user needs to add ~~NOCACHE~~ manually to page, to assure ACL rules are followed
			// coding here is too late, it doesn't get parsed
			// $renderer->doc .= "~~NOCACHE~~";
 
            // $data is an array
            // $data[1]..[x] are excluded namespaces, $data[0] is the report type
            //handle choices
            switch ($data[0]){
                case 'all':
                    $renderer->doc .= $this->all_pages($data);
                    break;
                case 'revisions':
                    $renderer->doc .= $this->all_pages($data, false);
                    break;
                default:
                    $renderer->doc .= "RevisionsDue syntax error";
                   // $renderer->doc .= "syntax ~~REVISIONS:<choice>~~<choice> :: all  Example: ~~REVISIONS:all~~";
            }
 
             return true;
        }
        return false;
    }
 
    function all_pages($params_array, $mandatory_revisions = true ) {
      global $conf;
      $result = '';
      $data = array();
      search($data,$conf['datadir'],'revision_callback_search_wanted',array('ns' => $ns));
      
      $result .= $this->revision_report_table($data, $mandatory_revisions);
 
      return $result;
    }

  function revision_report_table( $data, $mandatory_revisions = true )
  {
    global $conf;
  
    $count = 1;
    $output = '';
 
    // for valid html - need to close the <p> that is feed before this
    $output .= '</p>';
    $output .= '<table class="inline"><tr><th> # </th><th>Title</th><th>Last Revision</th><th>Frequency</th><th>Next Revision Date</th></tr>'."\n" ;
 
    uasort($data, 'date_compare');

  	foreach($data as $id=>$item)
    {
  		if( $item['revision'] === '' ) {
         continue ;
        }
        
        if ($mandatory_revisions === false and ($item['frequency'] === '' or !isset($item['frequency']))) {
              continue ;
        }
          
  		// $id is a string, looks like this: page, namespace:page, or namespace:<subspaces>:page
  		$match_array = explode(":", $id);
  		//remove last item in array, the page identifier
  		$match_array = array_slice($match_array, 0, -1);
  		//put it back together
  		$page_namespace = implode (":", $match_array);
  		//add a trailing :
  		$page_namespace = $page_namespace . ':';
 
  		//set it to show, unless blocked by exclusion list
  		$show_it = true;
  		foreach ($exclude_array as $exclude_item)
      {
  			//add a trailing : to each $item too
  			$exclude_item = $exclude_item . ":";
  			// need === to avoid boolean false
  			// strpos(haystack, needle)
  			// if exclusion is beginning of page's namespace , block it
  			if (strpos($page_namespace, $exclude_item) === 0){
  			   //there is a match, so block it
  			   $show_it = false;
  			}
  		}
 
  		if( $show_it )
      {
   	    $revision = $item['revision'];
   	    $frequency = $item['frequency'];
   	    $revision_date = $item['revision_date'];

//        msg('Creating teable for:'.$id.' was modified: '.date ("F d Y H:i:s.", $revision));

        $output .=  "<tr><td>$count</td><td><a href=\"". wl($id)
        . "\" class=\"" . "wikilink1"
        . "\"  onclick=\"return svchk()\" onkeypress=\"return svchk()\">"
        . $id .'</a></td><td>'.revision_string($revision)."</td><td>".$frequency."</td><td>".revision_string($revision_date)."</td></tr>\n";
 
  			$count++;
  		}
 
  	}
 
  	$output .=  "</table>\n";
  	//for valid html = need to reopen a <p>
  	$output .= '<p>';
 
    return $output;
  }

 
}
 
 
//Setup VIM: ex: et ts=4 enc=utf-8 :
?>
