<?php
/**
 * @file
 * Migration for Nodes data from the legacy Deusm database.
 */
class NodesDesignNewsMigration extends CanonSitesMigration 
{
	public $siteURL;
	public $siteIP;
	private $connection;
	public $imageDestination;
	public $imageHandler;
	private $src_id;
	private $fileImageField;
	private $host;
	private $username;
	private $password;
	private $default_thubmnail_fid;
	private $default_author_tid;
	public function __construct($arguments) 
	{
		parent::__construct($arguments);
		$this->default_thubmnail_fid = 21348; //prod
		$this->default_author_tid = 1127; //prod
		$this->host = '';
		$this->username = '';
		$this->password = '';
		$this->siteURL = '';
		$this->siteIP = '';
		$this->connection = 'dn';
		$this->imageDestination = 'sites/default/files/';
		$this->imageHandler = new ImageHandler();
		$this->description = t('Import Nodes data from the DN_sourcedata DB.');
		$query = $this->getConnection($this->connection)->select('deusmsectionxml', 'n');	
		//build the query
		$query->fields('n', array('id','DateId','DataContent','UBMArticleType','sectionId','headLine','displayURL','authorId','FirstCreated','Taxonomy','PrimaryTaxName','ByLine','displayURL'));
		//$query->condition('FirstCreated', '6/%%/2016%%', 'LIKE');
		$query->condition('headLine', '%' . 'slideshow' . '%', 'NOT LIKE');
		//$query->condition('FirstCreated', '%' . '2014' . '%', 'LIKE');
		//$query->condition('UBMArticleType', 1, '=' );
		//$query->condition('sectionId', 1362, '=' );
		$query->condition('UBMArticleType', 1, '=' );
		$query->condition('DataContent', "", '!=' );
		
		//map the old fields to new fields
		$this->addFieldMapping('title', 'headLine');//->callbacks(array($this, 'clean'));
		$this->addFieldMapping('title:format')->defaultValue('filtered_html');
		$this->addFieldMapping('field_main_topic','PrimaryTaxName');
		$this->addFieldMapping('field_byline','ByLine');
		$this->addFieldMapping('field_author');
		$this->addFieldMapping('field_status')->defaultValue("1");
		$this->addFieldMapping('status')->defaultValue("1");
		$this->addFieldMapping('body', 'DataContent')->callbacks(array($this, 'process_body_content'));
		$this->addFieldMapping('body:format')->defaultValue('full_html');
		$this->addFieldMapping('field_sub_topics', 'Taxonomy')->separator('|');
		$this->addFieldMapping('field_display_date', 'FirstCreated');
		$this->AddFieldMapping('domains')->defaultValue(3); //design news domain ID=3
		$this->source = new MigrateSourceSQL($query);
		$this->destination = new MigrateDestinationNode('article');
		
		//source data has 'id' int field for PK
		$this->map = new MigrateSQLMap(
		$this->machineName,
		array(
		'id' => array(
		  'type' => 'int',
		  'not null' => TRUE,
		  'description' => 'Node ID',
		  'alias' => 'n',
		 ),
		),
		  MigrateDestinationNode::getKeySchema()
		);
	}//constructor 
	function __destruct()
	{
		$closeMySQL = $this->mysqli->close();
		if($closeMySQL === false)
		{
			echo "Error Closing Connection.";
		}
	}//destructor 
	public function prepareRow($row) 
	{
		$this->src_id = $row->nid;
	}
	/*
	 * handles publishing, thumbails, domain access, authors, taxonomies, redirects
	*/
	public function complete($entity, $row) 
	{
		//Redirect old urls to new one
		$this->redirectURL($entity, $row);
		//id of newly created node
		$nid = $entity->nid;
		//id of source (used for join)
		$id = $row->id;
		// Associate the thumbnails with the article 
		$filename = $this->get_trimmed_filename($id);
		if (!empty($filename)) {
		  $fid = $this->get_fid_from_filename($filename);
		  if ($fid) {
			$this->insert_into_field_data_field_image($nid, $fid);
		  }
		}
		// Associate the author with the article 
		$name = $this->get_doc_editor_name($id);
		$tid = $this->get_author_tid($name);
		$this->insert_into_field_data_field_author($nid, $tid);
		$this->insert_into_taxonomy_index($nid, $tid);
		//print(var_dump($row));
		//print "Node ID: $nid \n";
		//print "Source ID: $id \n";
		// Associate the thumbnails with the article 
		$filename = $this->get_trimmed_filename($id);
		$fid = $this->get_fid_from_filename($filename);
		$this->insert_into_field_data_field_image($nid, $fid);
		//Set article to published
		$this->setNodeRevisionUnpublished($entity);
		$this->setModerationUnpublished($entity);
		$this->setModerationPublished($entity);
		$this->setNodePublished($entity);
		$this->setNodeRevisionPublished($entity);
		//Domain Access Settings
		$this->insertDomainCanonical($entity, $this->domain_id);
		$this->setDomainSource($entity, $this->domain_id);
		$this->setNodeAccess($entity->nid);
		$this->updateNodeAccess($entity->nid);
		//$this->setDomainAccess($entity->nid);
		//$this->updateDomainAccess($entity->nid);
		$this->setFieldImage($entity, $row);
		/* not needed if you run domain from correct drush alias (or if no alias run from UI)
		$this->update_domain_settings($nid); */
		print $entity->title . "\n";
		print "Node imported: $entity->nid \n";
	}
	/*
	 * Callback f(x) / bundler for body field
	*/
	public function process_body_content($row) 
	{
		//replace tokenized links with real images
		$row = $this->replace_legacy_imagelinks($row);  
		$row = htmlspecialchars_decode($row);
		//$row = htmlentities($row);
		$row = $this->cleanEncoding($row);
		//$row = $this->remove_nonexistant_images($row);
		return $row;
	}
	public function replace_legacy_imagelinks($html)
	{
		$dom = new DOMDocument;
		libxml_use_internal_errors(true);
		if($html)
		{
		$dom->loadHTML($html);
		}
		//identify links in the article body
		$link = $dom->getElementsByTagName('a');
		$count = $link->length;
		$imageDetails = array();
		for($i=0; $i<$count; $i++)
		{
			//parse out link values from links
			$link = $dom->getElementsByTagName('a')->item($i);
			$string = $link->attributes->getNamedItem("href")->value;
			//extrapolate the doc_id from the string
			preg_match('/doc_id=[^&]+/', $string, $matches);
			$match = $matches[0];
			$doc_id = str_replace('doc_id=', '', $match);
			$image_num = str_replace('doc_id='.$doc_id.'&image_number=', '', $string);
			//extrapolate the image_num from the string
			$image_num = str_replace('http://www.designnews.com/document.asp?', '', $image_num);
			//build up array of image details
			$imageDetails[$i]["image_num"]=$image_num;
			$imageDetails[$i]["doc_id"]=$doc_id;
			//do the work only if there are both required paramters
			if($imageDetails[$i]["doc_id"] && $imageDetails[$i]["image_num"])
			{
				//lookup image URL in doc_images_csv table based on docID and image_num
				$imageURL = $this->get_image_url_from_doc_id($imageDetails[$i]["doc_id"],$imageDetails[$i]["image_num"]);
				$imageDetails[$i]['image_url']=$imageURL;
			}
		}//for
		//clean up encoding
		$html = htmlspecialchars($html);
		$html = rtrim($html);
		foreach ($imageDetails AS $imageDetail)
		{
			$image_url = $imageDetail['image_url'];
			$image_num = $imageDetail['image_num'];
			$doc_id = $imageDetail['doc_id'];
			
			//replace tokenized links only if it has an image number! 
			if(is_numeric($image_num) && $image_num > 0 && $image_num == round($image_num, 0))
			{
				/* do the actual replacement of tokenized link with image url */
				$html = str_replace("&lt;a href=&quot;http://www.designnews.com/document.asp?doc_id=$doc_id","&lt;img src=&quot;http://www.designnews.com/document.asp?doc_id=$doc_id",$html);
				$html = str_replace("http://www.designnews.com/document.asp?doc_id=$doc_id&amp;image_number=$image_num","$image_url",$html);
			}	
		}//foreach
		return $html;
	}//f(x)
	/*
	 * Returns desum Image URL, decodes legacy link
	*/
	public function get_image_url_from_doc_id($doc_id,$image_num)
	{
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "dn_sourcedata");
		$query = "SELECT image_src FROM doc_images_csv WHERE doc_id = $doc_id AND image_number = $image_num";
		$result = $mysqli->query("$query");
		if (!$result || $result->num_rows==0)
		{
			//printf("Errormessage: %s\n", $mysqli->error);
			$image_url = "";
			return $image_url;
		}
		if ($result)
		{
			//pr($result);
			while ($row = $result->fetch_assoc()) 
			{
				$image_path = $row["image_src"];
			}//while
			//printf($query);
			$file = basename($image_path);
			$image_url = "/sites/default/files/".$file;
			return $image_url;
		}
	}
	/*
	 * Insert into field_data_field_author table
	*/
	public function get_sourceid($nid)
	 {
		 //print "Node ID: $nid \n";
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "SELECT * FROM migrate_map_nodesdn WHERE destid1 = $nid";
		$result = $mysqli->query("$query");
		while ($row = $result->fetch_assoc()) 
		{
			$sourceid = $row["sourceid1"];
		}
		return $sourceid;
	 }
	/*
	 * Returns filename from legacy datbase
	*/
	public function get_trimmed_filename($sourceid)
	{
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "dn_sourcedata");
		$query = "SELECT x.id, x.NewsItemId, x.HeadLine, i.image_src, i.image_number FROM deusmsectionxml x JOIN doc_images_csv i ON x.NewsItemId = i.doc_id WHERE id = $sourceid";
		//$query = "SELECT x.id, x.NewsItemId, x.HeadLine, i.image_src, i.image_number FROM desumxml x JOIN doc_images_csv i ON x.NewsItemId = i.doc_id WHERE id = $sourceid AND i.image_number = 1";
		$result = $mysqli->query("$query");
		if ($result->num_rows>0)
		{
			while ($row = $result->fetch_assoc()) 
			{
				$filename = $row["image_src"];
			}
		//printf('Success');
		}
		else{
			$filename = "";
		printf($query);
		//printf('No Featured Image in DB');
		printf("Errormessage: %s\n", $mysqli->error);
		}
		$path = $filename;
		$filename = basename($path);
		return $filename;
	}
	/*
	 * Returns fid for new file 
	*/
	public function get_fid_from_filename($filename)
	{
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "SELECT * FROM file_managed WHERE filename = '$filename'";
		//echo $query;
		$result = $mysqli->query("$query");
		if ($result)
		{
			while ($row = $result->fetch_assoc()) 
			{
				$fid = $row["fid"];
			}
		//printf('Success');
		}
		else{
		printf($query);
		printf("Errormessage: %s\n", $mysqli->error);
		}
		if(!$fid)
		{
			$fid = 10038;
			$fid = $this->default_thubmnail_fid;
		}
		return $fid;
	}
	/*
	 * Lookup authorId (email address) and extract name from legacy data
	 */
	public function get_doc_editor_name($sourceid)
	{
		$email="";
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "dn_sourcedata");
			$query = "SELECT * FROM deusmsectionxml WHERE id = $sourceid";
		$result = $mysqli->query("$query");
		if ($result->num_rows>0)
		{
			while ($row = $result->fetch_assoc()) 
			{
				$name = $row["authorId"];
			}
			//printf('Success');
		}
		else{
			printf($query);
			printf("Errormessage: %s\n", $mysqli->error);
			$name = "DesignNews Staff";
		}
		return $name;
	}
	/*
	 * Query taxonomy_term_data using doc_editor_name (legacy) and get tid for author
	 */
	public function get_author_tid($name)
	 {
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "SELECT * FROM field_data_field_email WHERE field_email_value = '$name';";
		$result = $mysqli->query("$query");
		if ($result->num_rows>0)
		{
			while ($row = $result->fetch_assoc()) 
			{
				$tid = $row["entity_id"];
			}
		}
		else{
			printf($query);
			printf("Errormessage: %s\n", $mysqli->error);
		}
		if(!$tid)
		{
			$tid = 2029;
			$tid = $this->default_author_tid;
			//design news staff author (LOCAL DEV)
		}
		return $tid;
	}
	/*
	 * Insert into field_data_field_author table
	*/
	public function insert_into_field_data_field_author($destid, $tid)
	{
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "INSERT INTO `d7_canon_media`.`field_data_field_author` (`entity_type`, `bundle`, `deleted`, `entity_id`, `language`, `delta`, `field_author_tid`) VALUES ('node', 'article', '0', '$destid', 'und', '0', '$tid');";
		$result = $mysqli->query("$query");
		if ($result)
		{
			//printf('Success');
			return true;
		}
		else{
			printf($query);
			printf("Errormessage: %s\n", $mysqli->error);
			return false;
		}
	}
	/*
	 * Insert into taxonomy_index
	*/
	 public function insert_into_taxonomy_index($destid, $tid)
	 {
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "INSERT INTO `d7_canon_media`.`taxonomy_index` (`nid`, `tid`, `sticky`, `created`) VALUES ('$destid', '$tid', '0', '1470752995');";
		$result = $mysqli->query("$query");
		
		if ($result)
		{
			return true;
		}
		else{
			printf($query);
			printf("Errormessage: %s\n", $mysqli->error);
		}	 
	 }
	/*
	* Insert record to field_data_field_image (to associate featured image with article) 
	*/
	public function insert_into_field_data_field_image($nid, $fid)
	{
		$mysqli = new mysqli("$this->host", "$this->username", "$this->password", "d7_canon_media");
		$query = "INSERT INTO `d7_canon_media`.`field_data_field_image` (`entity_type`, `bundle`, `deleted`, `entity_id`, `revision_id`, `language`, `delta`, `field_image_fid`, `field_image_width`, `field_image_height`) VALUES ('node', 'article', '0', '$nid', '$nid', 'und', '0', '$fid', '0', '0');";
		echo $query;
		$result = $mysqli->query("$query");
		if ($result)
		{
			return true;
		}
		else{
			printf($query);
			printf('<p style="color:red;">Failed3</p>');
			printf("Errormessage: %s\n", $mysqli->error);
			return false;
		}	 
	}
	/*
	 * Get redirect for newly imported node
	*/
	public function redirectURL($entity, $row) 
	{
		if ($row->displayURL != null) {
		  $redirect = new stdClass();
		  $source = str_replace('http://www.designnews.com/', '', $row->displayURL);
		  redirect_object_prepare(
			$redirect,
			array(
			  'source' => $source,
			  'source_options' => array(),
			  'redirect' => 'node/' . $entity->nid,
			  'redirect_options' => array(),
			  'language' => LANGUAGE_NONE,
			)
		  );
		  // Check if the redirect exists before saving.
		  $hash = redirect_hash($redirect);
		  if (!redirect_load_by_hash($hash)) {
			redirect_save($redirect);
		  }
		}
	}
	/*
	 * Clean up some nasty stuff in the legacy data
	*/
	public function clean($string) 
	{
		$string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
		$string =  preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
		$string = str_replace('-', ' ', $string); // Replaces all spaces with hyphens.
		return $string;
	}
}//class
?>
