<?php

#!# Links need baseUrl support
#!# Needs map view, so people can pick from a browseable map
#!# Replace database calls with prepared statements - those using escaping basically date back to the original insecure code


# Target volunteer signup system
class targetVolunteerSignup
{
	# Postcode regexp
	private $postcodeRegexp = '^[a-zA-Z]{1,2}[0-9R][0-9a-zA-Z]? [0-9][a-zA-Z]{2}$';	// Postcode adapted from http://www.govtalk.gov.uk/gdsc/html/frames/PostCode.htm ; see also http://en.wikipedia.org/wiki/UK_postcodes
	
	# Define universal limitations when doing school searches
	private $limitations = "isSchool='1' AND SchID NOT REGEXP '^LEGACY' AND SchName NOT REGEXP 'no longer' AND SchName NOT REGEXP 'now merged'";
	
	# Registered functions
	private $actions = array (
		'home' => array (
			'description' => 'Home',
			'url' => './',
		),
		'locate' => array (
			'description' => 'Find a school',
			'url' => 'locate.html',
		),
		'edit' => array (
			'description' => 'My schools',
			'url' => 'edit.html',
		),
		'schools' => array (
			'description' => 'Other volunteers',
			'url' => 'schools.html',
		),
		'areas' => array (
			'description' => 'Areas',
			'url' => 'areas.html',
			'menu' => false,
		),
		'message' => array (
			'description' => 'Send a message',
			'url' => 'message.html',
			'menu' => false,
		),
		'information' => array (
			'description' => 'Information about a school',
			'url' => 'information.html',
			'menu' => false,
		),
		'editschool' => array (
			'description' => "Edit a school's details",
			'url' => 'information.html',
			'menu' => false,
			'administrator' => true,
		),
		'feedback' => array (
			'description' => 'Feedback form',
			'url' => 'feedback.html',
		),
		'details' => array (
			'description' => 'My details',
			'url' => 'details.html',
		),
		'tips' => array (
			'description' => 'Volunteer tips',
			'url' => NULL,	// Defined by $this->settings['tipsUrl']
		),
		'signups' => array (
			'description' => '* Show signups',
			'url' => 'signups.html',
			'administrator' => true,
		),
		'loggedout' => array (
			'description' => 'Logout',
			'url' => 'logout.html',
		),
	);
	
	
	# Constructor, implementing a front controller
	#!# Need to migrate to FrontControllerApplication
	public function __construct ($settings)
	{
		# Load required libraries
		require_once ('application.php');
		require_once ('database.php');
		require_once ('pureContent.php');
		require_once ('ultimateForm.php');
		
		# Add fixed settings
		$settings['hostname'] = 'localhost';
		$settings['database'] = 'target';
		$settings['administrator'] = 'target@' . 'cusu.cam.ac.uk';
#		$settings['webmaster'] = 'webmaster@' . 'cusu.cam.ac.uk';
		$settings['webmaster'] = 'martin@lucas-smith.co.uk';
		$settings['feedback'] = 'targetfeedback@' . 'cusu.cam.ac.uk';
		
		# Assign the database credentials
		$this->settings = $settings;
		
		# Add the tipsUrl setting to the action
		$this->actions['tips']['url'] = $this->settings['tipsUrl'];
		
		# Load any stylesheet if supplied
		$this->settings['applicationStylesheet'] = '/styles.css';
		$reflector = new ReflectionClass (get_class($this));
		$applicationDirectory = dirname ($reflector->getFileName ());
		$stylesheet = $applicationDirectory . $this->settings['applicationStylesheet'];
		if (is_readable ($stylesheet)) {
			$styles = file_get_contents ($stylesheet);
			echo "\n\n" . '<style type="text/css">' . "\n\t" . str_replace ("\n", "\n\t", trim ($styles)) . "\n</style>\n";
		}
		
		# Show the header
		echo "\n" . '<h1>Target volunteer sign-up</h1>';
		
		# Define the baseUrl
		$this->baseUrl = application::getBaseUrl ();
		
		# Get the username or end
		if (!$this->username = $_SERVER['REMOTE_USER']) {
			application::utf8mail ($this->settings['webmaster'], 'Problem with Target Volunteer signup system on ' . $_SERVER['SERVER_NAME'], wordwrap ('The webserver is not requesting a Raven login, so the system is not receiving a username from the server, which it needs in order to continue.'));
			echo "<p class=\"warning\">Apologies - this facility is currently unavailable, as a technical error occured. The Webmaster has been informed and will investigate.</p>";
			return false;
		}
		
		# Connect to the database or end
		$this->databaseConnection = new database ($settings['hostname'], $settings['username'], $settings['password'], $settings['database']);
		if (!$this->databaseConnection->connection) {
			application::utf8mail ($this->settings['webmaster'], 'Problem with Target Volunteer signup system on ' . $_SERVER['SERVER_NAME'], wordwrap ('There was a problem with initalising the Target Volunteer signup system at the database connection stage. MySQL said: ' . mysql_error () . '.'));
			echo "<p class=\"warning\">Apologies - this facility is currently unavailable, as a technical error occured. The Webmaster has been informed and will investigate.</p>";
			return false;
		}
		
		# Get the student's details (or false if they are not registered), ending if not
		if ($this->user = $this->getUser ()) {
			
			# Show the menu
			echo $this->menu ();
		}
		
		# Get the batch
		$this->yearBatch = $this->yearBatch ();
		
		# Determine the requested action
		$this->action = (isSet ($_GET['action']) ? $_GET['action'] : false);
		
		# End if the user has insufficient privileges
		if ($insufficientPrivileges = (isSet ($this->actions[$this->action]['administrator']) && $this->actions[$this->action]['administrator'] && !$this->userIsAdministrator)) {
			echo "\n<p>The section you requested is available only to administrators. Please choose a section from the menu.</p>";
			return false;
		}
		
		# Take action
		if (!$this->user || !$this->action || (!array_key_exists ($this->action, $this->actions))) {
			$this->action = 'home';
		}
		$this->{$this->action} ();
	}
	
	
	# Define the database structure
	private function databaseStructure ()
	{
		return $sql = "
		
		-- Colleges
		CREATE TABLE `colleges` (
		  `CollegeID` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `CollegeName` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `CollegeDeleted` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'N',
		  PRIMARY KEY (`CollegeID`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		
		-- Feedback
		CREATE TABLE `feedback` (
		  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique key',
		  `datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Automatic timestamp',
		  `signup` int(11) NOT NULL COMMENT 'School',
		  `contactName` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Contact person at school/college',
		  `contactDetails` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'E-mail/phone for person at school/college (if known)',
		  `q1` text COLLATE utf8_unicode_ci COMMENT 'Q1. How many students did you talk to?',
		  `q2` text COLLATE utf8_unicode_ci COMMENT 'Q2. Were the students handpicked by teachers? Self nominated? Or was it a year group?',
		  `q3` text COLLATE utf8_unicode_ci COMMENT 'Q3. How were you received? (Were people interested? did they have questions to ask?)',
		  `q4` text COLLATE utf8_unicode_ci COMMENT 'Q4. What was the response of the teacher?',
		  `q5` text COLLATE utf8_unicode_ci COMMENT 'Q5. How could it have been better?',
		  `q6` text COLLATE utf8_unicode_ci COMMENT 'Q6. Any feedback on this website system?',
		  `q7` text COLLATE utf8_unicode_ci COMMENT 'Q7. Anything else you want to mention?',
		  PRIMARY KEY (`id`)
		) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Feedback';
		
		-- Postcodes
		CREATE TABLE `postcodes` (
		  `id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `postcode` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `x` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `y` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `latitude` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `longitude` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  PRIMARY KEY (`postcode`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		
		-- Schools
		CREATE TABLE `schools` (
		  `SchID` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'CAO ID (unique key field)',
		  `SchRegID` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'UCAS reference',
		  `SchName` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'School/college name',
		  `isSchool` tinyint(1) DEFAULT '0' COMMENT 'Whether it is a school',
		  `SchArea` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Area',
		  `SchAddressOne` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Address line 1',
		  `SchAddressTwo` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Address line 2',
		  `SchAddressThree` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Address line 3',
		  `SchAddressFour` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Address line 4',
		  `SchAddressFive` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Address line 5',
		  `SchPostcode` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Postcode',
		  `SchPhone` varchar(30) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Phone',
		  `SchEmail` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'E-mail address',
		  `SchType` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'School type',
		  `SchAdmissionPolicy` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Admission policy',
		  `SchSex` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Sex',
		  `SchAgeRange` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Age range',
		  `SchSixthFormSize` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Size of sixth form',
		  `SchDeleted` char(1) COLLATE utf8_unicode_ci DEFAULT 'N' COMMENT 'Entry is deleted?',
		  `targetVisitRequest` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Last year when Target Visit requested',
		  PRIMARY KEY (`SchID`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Original';
		
		-- Signups
		CREATE TABLE `signups` (
		  `SignID` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `SignSchID` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
		  `SignStdID` int(11) NOT NULL DEFAULT '0',
		  `SignStdBatch` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `SignVisitedStatus` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'N',
		  `SignVisitedDate` date DEFAULT NULL,
		  PRIMARY KEY (`SignID`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Original';
		
		-- Students
		CREATE TABLE `students` (
		  `StdID` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `StdFName` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `StdLName` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `StdPostCode` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
		  `StdCollege` int(11) DEFAULT NULL,
		  `StdEmail` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `StdBatch` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		  `StdConfExp` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'N',
		  `StdActive` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'N',
		  `StdDeleted` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'N',
		  `StdIsAdministrator` enum('0','1') COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
		  PRIMARY KEY (`StdID`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		";
	}
	
	
	# Menu
	private function menu ()
	{
		# Compile the available functions list
		foreach ($this->actions as $key => $attributes) {
			if (isSet ($attributes['menu']) && (!$attributes['menu'])) {continue;}
			if (isSet ($attributes['administrator']) && $attributes['administrator'] && !$this->userIsAdministrator) {continue;}	// Show admin functions if the user is an administrator
			$links[] = "<a href=\"{$attributes['url']}\">{$attributes['description']}</a>";
		}
		
		# Show if the user is an administrator
		$administratorHtml = ($this->userIsAdministrator ? '<br />[* Admin privileges]' : '');
		
		# Construct the menu
		$html  = "\n" . '<div id="signupmenu">';
		$html .= application::htmlUl ($links);
		$html .= "\n<p>Logged in as: <strong>{$this->username}</strong>{$administratorHtml}</p>";
		$html .= "\n</div>";
		
		# Show the HTML
		echo $html;
	}
	
	
	# Home page
	public function home ()
	{
		# Start the HTML
		echo "\n<p><strong>Welcome</strong> to the CUSU Target Volunteer scheme signup system.</p>";
		echo "\n<p>This system allows students who wish to volunteer their time and effort to visiting schools and colleges in their locality to check for local schools and sign-up to visit the school/college.</p>";
		
		# Require registration if they've not registered before
		if (!$this->user) {
			if (!$this->register ()) {
				return;
			}
		}
		
		# Actions
		echo "\n<h2>Add/view schools/colleges</h2>";
		echo "\n<p>You can <a href=\"locate.html\"><strong>add a school/college that you intend to visit</strong></a>.</p>";
		echo "\n<p>You can also <a href=\"edit.html\">list schools/colleges already selected</a>.</p>";
		echo "\n<h2>Tips on visiting a school/college</h2>";
		echo "\n<p>We have written some <a href=\"{$this->actions['tips']['url']}\">notes to help you with visiting a school/college</a>.</p>";
		echo "\n<h2>Feedback</h2>";
		echo "\n<p>Please <a href=\"edit.html\">indicate when you have visited a school/college</a>, and then <a href=\"feedback.html\">give feedback on the visit</a>. This is an important part of the overall Scheme.</p>";
	}
	
	
	# Function to create the personal details table
	public function details ()
	{
		# Select the user details to show
		$data = array (
			'Name' => $this->user['fullName'],
			'E-mail' => $this->user['StdEmail'],
			'Postcode' => $this->user['StdPostCode'],
			'College' => $this->user['collegeName'],
		);
		
		# Construct the HTML
		$html  = "\n" . '<h2 class="personal">My details</h2>';
		$html .= "\n<p>You are logged in and registered with the following details:</p>";
		$html .= application::htmlTableKeyed ($data);
		
		# Show the HTML
		echo $html;
	}
	
	
	# Show the schools data table
	public function schools ($global = false)
	{
		# Heading
		$html  = "\n" . '<h2 class="personal">' . ($global ? 'All school/college signups' : 'Other volunteers') . '</h2>';
		
		# Get the schools/colleges data
		$query = "SELECT * FROM schools,signups,students WHERE " . ($global ? '' : "SignStdID = {$this->user['StdID']} AND ") . "SignSchID = SchID AND StdID = SignStdID AND SignStdBatch = '{$this->yearBatch}' GROUP BY SchID ORDER BY " . ($global ? 'SignVisitedStatus,SchName' : 'SchName') . ';';
		$data = $this->databaseConnection->getData ($query);
		
		# Start with the school/college data
		if (!$data) {
			if ($global) {
				$html .= "\n<p>There have been no signups so far.</p>";
			} else {
				$html .= "\n<p>You are not currently signed up to any <a href=\"locate.html\">schools</a>.</p>";
			}
			echo $html;
			return false;
		}
		
		# Extract and assemble the data
		$schools = array ();
		foreach ($data as $schoolIndex => $school) {
			
			# Get the volunteer data for this school
			$query = "SELECT * FROM signups, students WHERE SignSchID = '{$school['SchID']}' AND StdID = SignStdID AND " . ($global ? '' : "StdID != {$school['StdID']} AND ") . "SignStdBatch = '{$this->yearBatch}'";
			$volunteers = $this->databaseConnection->getData ($query);
			
			# Loop through each volunteer, obtaining the name and encoding their keys, placing '2' at the start to frustrate brute-force decoding further
			$volunteerInfo = array ();
			foreach ($volunteers as $volunteerIndex => $volunteer) {
				//$volunteerInfo[$volunteerIndex]['name'] = htmlspecialchars (ucwords (strtolower ("{$volunteer['StdFName']} {$volunteer['StdLName']}")));
				$volunteerInfo[$volunteerIndex]['name'] = htmlspecialchars ("{$volunteer['StdFName']} {$volunteer['StdLName']}");
				$volunteerInfo[$volunteerIndex]['studentEncoded'] = base64_encode ('2' . $volunteer['StdID']);
				$volunteerInfo[$volunteerIndex]['schoolEncoded'] = base64_encode ('2' . $school['SchID']);
			}
			
			# Assign the table cell data
			$schools[$schoolIndex]['School name'] = '<a href="information.html?school=' . $school['SchID'] . '"><strong>' . htmlspecialchars (ucwords (strtolower ($school['SchName']))) . '</strong></a>';
			$schools[$schoolIndex]['School address'] = $this->schoolAddress ($school);
			$schools[$schoolIndex]['Status'] = ($school['SignVisitedStatus'] == 'Y' ? 'Visited' : 'Not visited');
			if ($global) {
				$ordinals = array (0 => 'First', 'Second', 'Third');
			} else {
				$ordinals = array (0 => 'First other', 'Second other');
			}
			foreach ($ordinals as $number => $description) {
				$schools[$schoolIndex]["{$description} volunteer"] = (isSet ($volunteerInfo[$number]) ? "<a href=\"message.html?two={$volunteerInfo[$number]['schoolEncoded']}&amp;one={$volunteerInfo[$number]['studentEncoded']}\">{$volunteerInfo[$number]['name']}</a>" : '');
			}
		}
		
		# Create the table cell data
		if ($global) {
			$html .= "\n<p>The list below is ordered by <strong>Status</strong> and then <strong>School/College name</strong>.</p>";
		} else {
			$html .= "\n<p>You can <a href=\"edit.html\">edit the list of your schools</a> if you need to.</p>";
		}
		$html .= application::htmlTable ($schools, $tableHeadingSubstitutions = array (), $class = 'lines', $showKey = false, $uppercaseHeadings = false, $allowHtml = true);
		
		# Return the HTML
		echo $html;
	}
	
	
	# Function to determine if the student has previously registered
	private function getUser ()
	{
		# Get the data
		$query = "SELECT * FROM students where StdEmail = '{$this->username}@cam.ac.uk'";
		$data = $this->databaseConnection->getOne ($query);
		
		# Return false if not registered
		if (!$data) {return false;}
		
		# Determine if the user is an administrator
		$this->userIsAdministrator = ($data['StdIsAdministrator']);
		
		# Construct shortcuts for the user
		//$data['fullName'] = htmlspecialchars (ucwords (strtolower ("{$data['StdFName']} {$data['StdLName']}")));
		$data['fullName'] = htmlspecialchars ("{$data['StdFName']} {$data['StdLName']}");
		$data['StdPostCode'] = htmlspecialchars (strtoupper ($data['StdPostCode']));
		$data['collegeName'] = $this->getColleges ($data['StdCollege']);
		
		# Assign the user details
		return $data;
	}
	
	
	
	# Registration system
	private function register ()
	{
		# Create the form
		$form = new form (array (
			'displayDescriptions' => true,
			'displayRestrictions' => false,
			'escapeOutput' => true,
			'formCompleteText' => false,
		));
		$form->heading (2, 'New registration');
		$form->heading ('p', 'Please register your details before continuing. Thank you for your interest in the scheme.');
		#!# Databind this instead
		$form->email (array (
			'name'		=> 'email',
			'title'		=> 'E-mail',
			'required'	=> true,
			'default'	=> $email = $this->username . '@cam.ac.uk',
			'editable'	=> false,
		));
		$form->input (array (
			'name'		=> 'forename',
			'title'		=> 'Forename',
			'required'	=> true,
		));
		$form->input (array (
			'name'		=> 'surname',
			'title'		=> 'Surname',
			'required'	=> true,
		));
		$form->input (array (
			'name'		=> 'postcode',
			'title'		=> 'Home postcode',
			'required'	=> true,
			'regexp'	=> $this->postcodeRegexp,
			'description'	=> 'Please ensure the space in the middle is present',
			'size'	=> 12,
			'maxlength'	=> 8,
		));
		$form->select (array (
			'name'			=> 'college',
			'title'			=> 'College',
			'values'		=> $this->getColleges (),
			'forceAssociative' => true,
			'required'		=> 1,
		));
		$form->heading ('p', '<em class="comment">Data is held and processed in accordance with data protection legislation and will not be passed on to third parties.</em>');
		
		# Process the form
		if (!$result = $form->process ()) {return false;}
		
		# Upper-case the postcode
		$result['postcode'] = strtoupper ($result['postcode']);
		
		# Insert the data
		#!# Currently relies on escapeOutput above; move to proper ->insert()
		$query = "INSERT INTO students (StdFName,StdLName,StdPostCode,StdCollege,StdEmail,StdBatch ) VALUES ( '{$result['forename']}','{$result['surname']}','{$result['postcode']}','{$result['college']}','{$email}','{$this->yearBatch}' )";
		if (!$this->databaseConnection->execute ($query)) {
			#!# Inform admin
			return false;
		}
		
		# Confirm success; NB No e-mail needs to be sent
		echo "\n" . '<p class="warning"><strong>Thank you</strong> for registering your details. These have been successfully recorded in the database.</p>';
		
		# Get the user's details out of the database
		$this->user = $this->getUser ();
		
		# Return success
		return true;
	}
	
	
	# Function to get the colleges
	private function getColleges ($particular = false)
	{
		# Get the data
		$query = "SELECT collegeID,collegeName from colleges where CollegeDeleted = 'N';";
		$data = $this->databaseConnection->getData ($query);
		
		# Arrange the data
		$colleges = array ();
		foreach ($data as $id => $college) {
			$colleges[$college['collegeID']] = $college['collegeName'];
		}
		
		# Return one only if required
		if ($particular && isSet ($colleges[$particular])) {
			return $colleges[$particular];
		}
		
		# Return the data
		return $colleges;
	}
	
	
	# Function to determine the current year batch
	private function yearBatch ()
	{
		# Create the year batch string (e.g. 2005-2006)
		$year = date ('Y');
		$yearBatch = ((date ('m') >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year));
		
		# Return the string
		return $yearBatch;
	}
	
	
	# Feedback page
	public function feedback ()
	{
		# Title
		echo "\n" . '<h2 class="questionnaire">Feedback questionnaire</h2>';
		echo "\n" . '<p>Feedback is an important part of the Target Visit. Please fill out this short questionnaire to give the organisers of the Target Campaign information about the Visit.</p>';
		
		# Get schools or end
		if (!$signups = $this->getSignups ()) {
			echo "\n<p><strong>You must have <a href=\"locate.html\">signed up to a school</a> before you can give feedback on it!</strong></p>";
			return false;
		}
		
		# Determine if a signup has been selected
		$signup = ((isSet ($_GET['signup']) && array_key_exists ($_GET['signup'], $signups)) ? $_GET['signup'] : NULL);
		
		# Create the form
		$form = new form (array (
			'formCompleteText' => 'Many thanks for taking the time to undertake the visit and to give your feedback. Your feedback is valuable to us for visits in future years.',
			'display' => 'paragraphs',
			'displayRestrictions' => false,
			'databaseConnection' => $this->databaseConnection,
			'cols'		=> 60,
			'rows'		=> 4,
		));
		$form->input (array (
			'name'		=> 'from',
			'title'		=> 'From',
			'required'	=> true,
			'default'		=> $this->user['fullName'],
			'editable'	=> false,
		));
		$form->email (array (
			'name'		=> 'email',
			'title'		=> 'E-mail',
			'required'	=> true,
			'default'		=> $this->user['StdEmail'],
			'editable'	=> false,
		));
		$form->dataBinding (array (
			'database' => $this->settings['database'],
			'table' => 'feedback',
			'intelligence' => true,
			'attributes' => array (
				'signup' => array ('type' => 'radiobuttons', 'values' => $signups, 'forceAssociative' => true, 'default' => $signup, 'editable' => !$signup),
			),
		));
		
		# Output methods
		$form->setOutputScreen ();
		$form->setOutputEmail ($this->settings['feedback'], $this->settings['webmaster'], $subjectTitle = 'CUSU Target Volunteer: feedback from visit', NULL, 'email');
		
		# Process the form
		if (!$result = $form->process ()) {return false;}
		
		# Unset unwanted fields
		unset ($result['from']);
		unset ($result['email']);
		
		# Insert the data into the database
		$this->databaseConnection->insert ($this->settings['database'], 'feedback', $result);
	}
	
	
	# Function to get the schools registered to a user
	private function getSignups ()
	{
		# Get the data
		$query = "SELECT SignID,SchName,SchPostcode FROM students, signups, schools WHERE StdEmail = '{$this->user['StdEmail']}' AND SignStdID = StdID AND SchID = SignSchID AND SignStdBatch = '{$this->yearBatch}'";
		$data = $this->databaseConnection->getData ($query);
		
		# Assemble the data
		$schools = array ();
		foreach ($data as $index => $school) {
			$schools[$school['SignID']] = ucwords (strtolower ($school['SchName'])) . ' - ' . strtoupper ($school['SchPostcode']);
		}
		
		# Return the data
		return $schools;
	}
	
	
	# Function to send a message to a volunteer
	public function message ()
	{
		# Show the title
		echo "\n" . '<h2 class="questionnaire">Message a volunteer</h2>';
		
		# Get the name and school from the URL
		if (!$urlData = $this->getMessageRecipientInfo ()) {
			echo "<p class=\"warning\">Invalid URL - please check and try again.</p>";
			return false;
		}
		
		# Create the form
		$form = new form (array (
			'formCompleteText' => 'Your message has been sent.',
			#!# Remove hardcoded URL
			'emailIntroductoryText' => "This email has been sent from the CUSU Target Volunteer website at {$_SERVER['_SITE_URL']}{$this->baseUrl}/ by another volunteer, {$urlData['name']}, because they have signed up to visit the same school/college as you, {$urlData['school']}.\n\n----",
		));
		$form->heading ('p', "<strong>This form will send a message to {$urlData['name']} regarding {$urlData['school']}.</strong>");
		$form->input (array (
			'name'		=> 'from',
			'title'		=> 'From',
			'required'	=> true,
			'default'		=> $this->user['StdEmail'],
			'editable'	=> false,
		));
		$form->input (array (
			'name'		=> 'name',
			'title'		=> 'To',
			'required'	=> true,
			'default'		=> $urlData['name'],
			'editable'	=> false,
			'discard'	=> true,
		));
		$form->input (array (
			'name'			=> 'school',
			'title'			=> 'Regarding school/college',
			'default'		=> $urlData['school'],
			'editable'		=> false,
			'required'		=> true,
		));
		$form->textarea (array (
			'name'		=> 'message',
			'title'		=> 'Message',
			'cols'		=> 60,
			'rows'		=> 6,
			'required'		=> true,
		));
		
		# Specify that the results should be e-mailed
		$form->setOutputEmail ($urlData['email'], $this->settings['webmaster'], 'CUSU Target Volunteer: contact regarding ' . $urlData['school'], NULL, 'from');
		
		# Process the form
		if (!$result = $form->process ()) {return false;}
	}
	
	
	# Function to get the name and school from the URL
	private function getMessageRecipientInfo ()
	{
		# Ensure that the volunteer ID and school have been supplied
		if (!isSet ($_GET['one']) || !isSet ($_GET['two'])) {
			return false;
		}
		
		# Extract the obfuscated volunteer and school IDs
		$personId = substr (base64_decode ($_GET['one']), 1);
		$schoolId = substr (base64_decode ($_GET['two']), 1);
		
		# Ensure numeric
		if (!ctype_digit ($personId)) {
			return false;
		}
		
		# Get the volunteer info
		$query = "SELECT * FROM students WHERE StdID={$personId};";
		$person = $this->databaseConnection->getOne ($query);
		
		# Get the school info
		$query = "SELECT * FROM schools WHERE SchID='" . addslashes ($schoolId) . "' and SchDeleted != 'Y';";
		$school = $this->databaseConnection->getOne ($query);
		
		# Ensure both are found
		if (!$person || !$school) {
			return false;
		}
		
		# Extract the data from the arrays
		$urlData['name'] = htmlspecialchars (ucwords (strtolower ("{$person['StdFName']} {$person['StdLName']}")));
		$urlData['school'] = htmlspecialchars (ucwords (strtolower ($school['SchName'])));
		$urlData['email'] = $person['StdEmail'];
		
		# Return the data
		return $urlData;
	}
	
	
	# School locator function
	public function locate ()
	{
		# Start the page
		echo "\n" . '<h2 class="locator">School locator</h2>';
		
		# Check if there are any schools in the database at all
		$query = "SELECT COUNT(SchID) AS count FROM schools WHERE {$this->limitations};";
		$result = $this->databaseConnection->getOne ($query);
		if (!$result['count']) {
			echo "<p>No schools/colleges were found. Most likely this is because the data for this academic year has not been input yet. Please contact the <a href=\"/contacts/access/\">Access Officer</a> if necessary about this.</p>";
			return false;
		}
		
		# See if an area is given
		if ($area = (isSet ($_GET['area']) ? $_GET['area'] : NULL)) {
			$this->showLocatedSchools (false, $area);
			return;
			
		# State machine: check whether it's on the second form
		} elseif (!isSet ($_POST['confirmation'])) {
			
			# Create the location form
			if (!$result = $this->locateForm ()) {return false;}
			
			# Show the located schools
			$this->showLocatedSchools ($result);
			
			# End here
			return;
		}
		
		# Confirm schools
		$this->locateConfirmation ();
	}
	
	
	
	# Function to create a locate form
	private function locateForm ()
	{
		# Create the form
		$form = new form (array (
			'displayRestrictions' => false,
			'formCompleteText' => false,
		));
		$form->heading ('p', 'You can either <a href="areas.html">pick an area from an A-Z list</a>, or search on your <strong>postcode</strong> and select a <strong>radius</strong> within which you are willing to travel.');
		$form->input (array (
			'name'		=> 'postcode',
			'title'		=> 'Postcode',
			'required'	=> true,
			'regexp'	=> $this->postcodeRegexp,
			'default'	=> $this->user['StdPostCode'],
			'description'	=> 'Please ensure the space in the middle is present',
			'size'	=> 12,
			'maxlength'	=> 8,
		));
		$form->radiobuttons (array (
			'name'			=> 'radius',
			'title'			=> 'Radius',
			'values'		=> array (2 => '2 miles', 5 => '5 miles', 10 => '10 miles', 20 => '20 miles', 30 => '30 miles'),
			'forceAssociative' => true,
			'required'		=> 1,
			'default'		=> '5 miles',
		));
		
		# Process the form
		if (!$result = $form->process ()) {return false;}
		
		# Upper-case the postcode
		$result['postcode'] = strtoupper ($result['postcode']);
		
		# Return the result
		return $result;
	}
	
	
	# Function to list schools found
	private function showLocatedSchools ($result, $area = false)
	{
		/* #!# This section needs re-writing. At present it works out the postcodes, then the distances, and then gets the schools.
		   Because the schools are obtained by several SQL statements, the ordering is thus via postcode, which is unhelpful. Ordering ought to be by distance or by school name.
		   Ideally it would be that the postcodes and distances are obtained, then ALL postcodes supplied to the schools as a single REGEXP lookup in the SQL.
		*/
		
		# Get the schools if an area is supplied
		if ($area) {
			$query = "SELECT SchID,SchPostcode,SchName,targetVisitRequest FROM schools WHERE {$this->limitations} AND SchArea='" . addslashes ($area) . "';";
			$schoolRows = $this->schoolRow ($query);
		} else {
			
			# Ensure the radius is not too large, to prevent overloading
			$radius = (int) $result['radius'];
			if ($radius > 30) {$radius = 30;}
			
			# Obtain the postcode posted by the user
			$postcodeParts = explode (' ', $result['postcode'], 2);
			$selectedPostcode = $postcodeParts[0];
			
			# Get the postcodes in the area bounced by the radius from the user's postcode, or require the user to select from a list if not found
			if (!$boundedPostcodes = $this->inRadius ($selectedPostcode, $radius)) {
				$this->areas();
				return false;
			}
			
			# Loop through each bounded postcode and extract from the array the postcode itself
			$distances = array ();
			foreach ($boundedPostcodes as $boundedPostcode) {
				$postcode = $boundedPostcode['postcode'];
				
				# Work out the distance from the selected postcode to the bounced postcode
				$distance = $this->distance ($selectedPostcode, $postcode);
				$distance = (is_string ($distance) ? $distance : round ($distance));
				
				# Find the schools with this postcode
				$query = "SELECT SchID FROM schools WHERE SchPostcode Like '{$postcode} %';";
				$schools = $this->databaseConnection->getData ($query);
				
				# Loop through each school and add the postcode distance to the array of postcode distances if it's not already there
				foreach ($schools as $school) {
					if (!isSet ($distances[$postcode])) {
						$distances[$postcode] = $distance;
					}
				}
			}
			
			$schoolRows = '';
			foreach ($distances as $postcode => $distance) {
				$query = "SELECT SchID,SchName,SchPostcode,targetVisitRequest FROM schools WHERE {$this->limitations} AND SchPostcode LIKE '{$postcode} %'";
				$schoolRows .= $this->schoolRow ($query, $distance);
			}
		}
		
		# Start the form
		echo '<p>Schools/Colleges ' . ($area ? 'in ' . htmlspecialchars ($area) : "within {$radius} miles of <strong>{$result['postcode']}</strong>") . ', ordered by postcode:</p>';
		#!# This message should only appear if any are in the list!
		echo '<p>Note: some schools in our database have specifically requested a visit and <span class="requested">are marked in blue</span>. <strong>Please prioritise those if possible</strong> if any are shown below.</p>';
		echo '<form name="signUP" action="locate.html" method="post">';
		echo '<input name="confirmation" type="hidden" value="true">';
		echo '<table class="lines">';
		echo "<tr>
		<th>Postcode</th>
			<th>School name</th>
			<th>Target visit specifically requested?</th>
			<th>Distance (miles)</th>
			<th>Select</th>
			<th>Signed up</th>
		</tr>";
		echo $schoolRows;
		echo "<tr>";
		echo "<td colspan=\"5\" align=\"right\">";
		echo "<input type=\"submit\" value=\"Sign Up\" class=\"buttons\"></td>";
		echo "</tr>";
		echo "</table>";
		echo '</form>';
	}
	
	
	# Function to create a row for the school
	private function schoolRow ($query, $distance = false)
	{
		
/* ORDER BY SchName, targetvisitRequest DESC; */
		
		# Get the data
		$schoolDetails = $this->databaseConnection->getData ($query);
		if (!$schoolDetails) {return false;}
		
		// Calling Distance Finding function
		$html = '';
		foreach ($schoolDetails as $index => $name) {
			$html .= '<tr' . ($name['targetVisitRequest'] ? ' class="requested"' : '') . '>';
			$html .= "<td>" . strtoupper ( $name['SchPostcode'] ) . "</td>";
			$html .= "<td>" . '<a href="information.html?school=' . $name['SchID'] . '">' . htmlspecialchars (ucwords (strtolower ($name['SchName']))) . '</a>' . "</td>";
			#!# Limit to current year
			$html .= "<td>" . ($name['targetVisitRequest'] ? "Visit specifically requested for " . trim ($name['targetVisitRequest']) : '') . '</td>';
			$html .= "<td>" . ($distance ? (is_string ($distance) ? $distance : round ($distance)) : '&nbsp;'). '</td>';
			
			$query = "SELECT * FROM signups where SignSchID = '" . $name['SchID'] . "' AND SignStdBatch = '{$this->yearBatch}'";
			$data = $this->databaseConnection->getData ($query);
			
			if (count ($data) == 3) {
				$html .= "<td align=\"center\">Not available for signup</td>";
				$html .= "<td>&nbsp;3</td>";
			} else {
				$query = "select * from signups where SignSchID ='" . $name['SchID'] . "' and SignStdID = " . $this->user['StdID'] . " and SignStdBatch = '{$this->yearBatch}'";;
				$data2 = $this->databaseConnection->getData ($query);
				if (!$data2) {
					$html .= "<td align=\"center\"><input name=\"check_sign[]\" type=\"checkbox\" value=\"" . $name['SchID'] . "\" class=\"checkbox\"></td>";
				} else {
					$html .= "<td align=\"center\" class=\"comment\">(You've already signed up)</td>";
				}
				$html .= "<td>&nbsp;" . count ($data) . "</td>";
			}
			$html .= "</tr>";
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to find postcodes within given radius
	private function inRadius ($postCode, $radius)
	{
		# Get the information for the supplied postcode or end if postcode not found
		$query = "SELECT * FROM postcodes WHERE postcode='$postCode'";
		if (!$data = $this->databaseConnection->getOne ($query)) {
			return false;
		}
		
		# Get the latitude and longitude for this postcode
		$latitude = $data['latitude'];
		$longitude = $data['longitude'];
		
		# Search for postcodes within the radius
		$query = "SELECT * FROM postcodes WHERE (POW((69.1*(longitude-\"{$longitude}\")*cos($latitude/57.3)),\"2\")+POW((69.1*(latitude-\"{$latitude}\")),\"2\"))<($radius*{$radius});";
		$postcodes = $this->databaseConnection->getData ($query);
		
		# Return the postcodes
		return $postcodes;
	}
	
	
	//Distance Miles Calculation Function
	private function distance ($postcode1, $postcode2)
	{
		# Return a distance of zero if they're the same
		if ($postcode1 == $postcode2) {return '&lt;1';}
		
		# Obtain the latitude and longitude
		list ($latitude1, $longitude1) = $this->getLatLong ($postcode1);
		list ($latitude2, $longitude2) = $this->getLatLong ($postcode2);
		
		# Convert all the degrees to radians
		$latitude1 = $latitude1 * M_PI/180.0;
		$longitude1 = $longitude1 * M_PI/180.0;
		$latitude2 = $latitude2 * M_PI/180.0;
		$longitude2 = $longitude2 * M_PI/180.0;
		
		# Find the deltas
		$delta_lat = $latitude2 - $latitude1;
		$delta_lon = $longitude2 - $longitude1;
		
		# Find the Great Circle distance
		$temp = pow(sin($delta_lat/2.0),2) + cos($latitude1) * cos($latitude2) * pow(sin($delta_lon/2.0),2);
		$EARTH_RADIUS = 3956;
		$distance = $EARTH_RADIUS * 2 * atan2(sqrt($temp),sqrt(1-$temp));
		$distance = acos(sin($latitude1)*sin($latitude2)+cos($latitude1)*cos($latitude2)*cos($longitude2-$longitude1)) * $EARTH_RADIUS ;
		
		# Return the distance
		return $distance;
	}
	
	
	# Function to get latitude and longitude from a postcode
	private function getLatLong ($postcode)
	{
		$query = "SELECT * FROM postcodes WHERE postcode = '{$postcode}'";
		if (!$data = $this->databaseConnection->getOne ($query)) {
			#!# Is this error handling needed?
			echo "\n<p>Postcode {$postcode} not found</p>";
			return false;
		}
		
		# Return the data
		return array ($data['latitude'], $data['longitude']);
	}
	
	
	# Function to insert chosen school(s) and confirm this
	private function locateConfirmation ()
	{
		$schoolIds = $_POST['check_sign'];
		
		if (!is_array ($_POST['check_sign'])) {
			#!# Handle this
//			header('location: school_signup_error.php?postCode=' . $postCode);
			exit ();
		}
		
		# Check that there are currently less than 3 volunteers signed up (i.e. there is a space)
		foreach ($schoolIds as $schoolId) {
			$schoolId = addslashes ($schoolId);
			$query = "SELECT * FROM signups where SignSchID = '{$schoolId}' AND SignStdBatch = '{$this->yearBatch}'";
			$data = $this->databaseConnection->getData ($query);
			if (count ($data) < 3) {
				
				# Ensure the user has not refreshed the page (which add themselves again as a volunteer), by checking for the same data in the database
				$query = "SELECT * FROM signups where SignSchID = '{$schoolId}' AND SignStdID = {$this->user['StdID']} AND SignStdBatch = '{$this->yearBatch}'";
				$data = $this->databaseConnection->getData ($query);
				if (!$data) {
					
					# Insert the selected school for that user into the database
		 			$query = "INSERT INTO signups ( SignSchID, SignStdID, SignStdBatch ) values ( '" . $schoolId . "' , " . $this->user['StdID'] . " , '" . $this->yearBatch . "' )";
					#!# Handle error
					$this->databaseConnection->execute ($query);
				}
			}
		}
		
		# Confirm the data
		echo '<table class="lines">';
		echo "<tr>
		   <th>Postcode</th>
		      <th>School name</th>
		      <th>Status</th>
		      <th>Total volunteers now signed up</th>
		   </tr>";
		   
		# Loop through each submitted value to get the data out of the database (part of the entries for which will have just been created)
		foreach ($_POST['check_sign'] as $value ) {
			$value = addslashes ($value);
			$query = "SELECT * FROM signups, schools WHERE SignSchID = '" . $value . "' and SignStdID = " . $this->user['StdID'] . " and SignSchID = SchID and SignStdBatch = '{$this->yearBatch}'";;
			$data = $this->databaseConnection->getData ($query);
			echo "<tr>";
			if ($data) {
				echo "<td>" . strtoupper ( $data[0]['SchPostcode'] ) . "</td>";
				echo "<td>" . ucwords( strtolower( $data[0]['SchName'] ) ) . "</td>";
				echo "<td>Confirmed</td>";
				
				$query = "SELECT * FROM signups WHERE SignSchID ='" . $value . "' AND SignStdBatch = '{$this->yearBatch}';";
				$data = $this->databaseConnection->getData ($query);
				echo "<td>" . count ($data) . "</td>";
			} else {
				$query = "SELECT * FROM schools WHERE SignSchID = '" . $value . "'";
				$data = $this->databaseConnection->getData ($query);
				echo "<td>" . strtoupper ( $data[0]['SchPostcode'] ) . "</td>";
				echo "<td>" . ucwords(strtolower( $data[0]['SchName'] )) . "</td>";
				echo "<td>3 volunteers have already selected this school</td>";
				echo "<td>3</td>";
			}
		}
		echo "</tr>";
		echo "</table>";
		
		# Show a link to restart the list
		echo "\n" . '<p><a href="locate.html">Search again</a></p>';
	}
	
	
	# Function to construct a school's address
	private function schoolAddress ($data)
	{
		# Construct the address
		$addressLines = array ($data['SchAddressOne'], $data['SchAddressTwo'], $data['SchAddressThree'], $data['SchAddressFour'], $data['SchAddressFive']);
		foreach ($addressLines as $index => $addressLine) {
			if (empty ($addressLine)) {
				unset ($addressLines[$index]);
				continue;
			}
			$addressLines[$index] = ucwords (strtolower ($addressLine));
		}
		$address = implode (',<br />', $addressLines);
		
		# Return the address
		return $address;
	}
	
	
	# Function to show infomation about a school
	public function information ($editmode = false)
	{
		# Ensure a school has been supplied
		if (!isSet ($_GET['school']) || (empty ($_GET['school']))) {
			echo "<p class=\"warning\">Not found. Please check the URL and try again.</p>";
			return false;
		}
		
		# Query the database
		#!# != 'Y' probably needs fixing
		$query = "SELECT * FROM schools WHERE {$this->limitations} AND SchDeleted !='Y' AND SchID = '" . addslashes ($_GET['school']) . "';";
		if (!$data = $this->databaseConnection->getOne ($query)) {
			#!# Issue 404
			echo "<p>The School number you requested not found. Please check the URL and try again.</p>";
			return false;
		}
		
		# View mode
		if (!$editmode) {
			
			# Construct the data
			foreach ($data as $key => &$value) {
				$value = nl2br (htmlspecialchars (ucwords (strtolower ($value))));
			}
			
			# Compile the data
			$data = array (
				'School name' => $data['SchName'],
				'Area' => $data['SchArea'],
				'Address' => $this->schoolAddress ($data),
				'Postcode' => strtoupper ($data['SchPostcode']),
				'Phone number' => (substr ($data['SchPhone'], 0, 1) == '1' ? '0' : '') . $data['SchPhone'],
				#!# strtolower here is a nasty workaround
				'E-mail' => strtolower ($data['SchEmail']),
			);
			
			# Build the HTML
			#!# Addition of '0' to start of phone numbers starting with '1' is hack due to Excel CSV bug when exporting from XLS to CSV
			$html  = "\n<h2 class=\"schools\">Add/view school details</h2>";
			//$html .= "\n<ul>\n\t<li><a href=\"edit.html\">Back to list of schools</a></li>\n</ul>";
			$html .= "\n<p>All the details we have been supplied for this school are below.<br />A <a href=\"http://www.google.co.uk/search?q=" . rawurlencode ($data['School name'] . ', ' . $data['Area']) . "\" target=\"_blank\">Google search for the school</a> may find more details.</p>";
			$html .= application::htmlTableKeyed ($data, array (), false, 'lines', $allowHtml = true);
			
			# Add editing link if the user is an administrator
			if ($this->userIsAdministrator) {
				$html .= "<p>As an administrator, you can <a href=\"editschool.html?school={$_GET['school']}\"><strong>edit this data</strong></a>.</p>";
			}
		} else {
			
			# Heading
			$html  = "\n<h2 class=\"schools\">Add/view school details</h2>";
			
			# Warn the user about the transferability of data
			$html .= "\n<p class=\"warning\">WARNING: <strong>This editing facility is intended for minor fix-ups.</strong> Bear in mind that edits here will get wiped next time the Webmaster is asked to batch-import a fresh spreadsheet of the data (usually once a year).</p>";
			
			# Create the form
			$form = new form (array (
				'formCompleteText' => false,
				'displayRestrictions' => false,
				'databaseConnection' => $this->databaseConnection,
			));
			$form->dataBinding (array (
				'database' => $this->settings['database'],
				'table' => 'schools',
				'data' => $data,
				'attributes' => array (
					'SchID' => array ('type' => 'textarea', 'editable' => false),
				//	'signup' => array ('type' => 'radiobuttons', 'values' => $signups, 'forceAssociative' => true, 'default' => $signup, 'editable' => !$signup),
				),
			));
			
			# Process the form
			if ($result = $form->process ($html)) {
				
				# Update the database
				if (!$this->databaseConnection->update ($this->settings['database'], schools, $result, array ('SchID' => $data['SchID']))) {
					#!# Inform admin
					echo "\n<p class=\"warning\">There was a problem updating the data - please report this to the Webmaster.</p>";
				} else {
					return $this->information ();
				}
			}
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to edit details of a school
	public function editschool ()
	{
		# Hand off to the information function
		return $this->information ($editmode = true);
	}
	
	
	# Function to edit the user's school information
	public function edit ()
	{
		# Start the HTML
		echo '<h2 class="edit">My schools</h2>';
		echo "\n<p>You can <a href=\"schools.html\">see who else plans to visit these schools</a> and make contact with those people.</p>";
		
		# Change the visited status if required
		if (isSet ($_POST['Submit']) && ($_POST['Submit'] == 'Update')) {
			$update = false;
			$cntk = $_POST['countk'];
			for ($i = 0; $i < $cntk; $i++ )  {
				$updatestatus = (isSet ($_POST['schstatus'. $i]) ? $_POST['schstatus'. $i] : false);
				$updatesignid = (isSet ($_POST['signid'. $i]) ? $_POST['signid'. $i] : false);
				if ($updatestatus){
					$query = "UPDATE signups set SignVisitedStatus ='" .  addslashes ($updatestatus) . "' where SignID= " . addslashes ($updatesignid);
					#!# Error handling needed
					if ($this->databaseConnection->execute ($query)) {
						$update = true;
					}
				}
			}
			if ($update) {echo '<p><strong>Status has been updated.</strong></p>';}
			
		# Delete item if requested
		} else if (isSet ($_GET['school'])) {
			$query = "DELETE FROM signups where SignID = " . addslashes ($_GET['school']);
			#!# Error handling needed
			if ($this->databaseConnection->execute ($query)) {
				echo '<p><strong>The selected school has been deleted.</p>';
			}
		}
		
		# Get the data
		$query = "SELECT SchID,SchName,SchArea,SignVisitedStatus,SignID from schools, signups where SignStdID = {$this->user['StdID']} and SignSchID = SchID and SignStdBatch = '{$this->yearBatch}' ORDER BY SchName;";
		$data = $this->databaseConnection->getData ($query);
		
		# End if no schools
		if (!$data) {
			echo "\n<p>You are not currently signed up to any <a href=\"locate.html\">schools</a>.</p>";
			return false;
		}
		
		# Assemble the table
		$schools = array ();
		$k = 0;
		foreach ($data as $schoolIndex => $school) {
			$schools[$schoolIndex]['School name'] = '<a href="information.html?school=' . $school['SchID'] . '">' . htmlspecialchars (ucwords (strtolower ($school['SchName']))) . '</a>';
			$schools[$schoolIndex]['Area'] = ucwords (strtolower ($school['SchArea']));
			$schools[$schoolIndex]['Status'] = '<select name="schstatus' . $k . '"><option value="Y"' . ($school['SignVisitedStatus'] == 'Y' ? ' selected="selected"' : '') . '>Visited</option><option value="N"' . ($school['SignVisitedStatus'] != 'Y' ? ' selected="selected"' : '') . '>Not visited</option></select>';
			$schools[$schoolIndex]['Delete?'] =
				'<input type="hidden" name="signid' . $k . '" value="' . $school['SignID'] . '">' . 
				'<a href="edit.html?school=' . $school['SignID'] . '" onclick="return go_there();"><img name="delSchSign"  src="images/delete.gif" width="10" height="12" border="0" alt="Delete School" ></a>';
	            $k++;
			$schools[$schoolIndex]['Feedback'] = "<a href=\"feedback.html?signup={$school['SignID']}\">Give feedback</a>";
		}
		
		# Show the HTML
		echo '<SCRIPT language="JavaScript">
			<!--
			function go_there()
			{
			var where_to = confirm("Do you really want to delete this school from your list of schools?");
			 if (where_to == true) {
			  document.updateschool.submit();
			  } else {
		     return false;
			  }
			 return true;
			}
			//-->
			</SCRIPT>';
		echo "\n" . '<form name="updateschool" action="edit.html" method="post">';
		echo "\n" . application::htmlTable ($schools, array (), $class = 'lines', $showKey = false, $uppercaseHeadings = false, $allowHtml = true);
		echo "\n" . '<input type="hidden" name="countk" value="' . $k . '" />';
		echo "\n" . '<p><input type="submit" name="Submit" value="Update" class="buttons" /></p>';
		echo "\n" . '</form>';
	}
	
	
	# Function to show areas
	public function areas ($embedded = false)
	{
		$html = '';
		if ($embedded) {
			$html .= 'Either select your area from an alphabetical list:<br />';
		} else {
			# Add the page title if not embedded
			if ($this->action == 'areas') {
				$html .= "\n" . '<h2 class="locator">School locator</h2>';
				$html .= "\n<p>Please choose your nearest area:</p>";
			} else {
				$html .= "\n<p>That postcode was not found. Please choose your nearest area:</p>";
			}
		}
		
		# Determine whether a letter is selected
		$letterSelected = ((isSet ($_GET['letter']) && (strlen ($_GET['letter']) == 1)) ? addslashes ($_GET['letter']) : 'a');
		
		# Create an alphabet list
		$alphabet = 'abcdefghijklmnopqrstuvwxyz';
		for ($i = 0; $i <= strlen ($alphabet); $i++) {
			$letter = substr ($alphabet, $i, 1);
			$links[] = "<a href=\"areas.html?letter={$letter}\">" . ($letterSelected == $letter ? '<strong>' : '') . strtoupper ($letter) . ($letterSelected == $letter ? '</strong>' : '') . '</a>';
		}
		$html .= implode (' ', $links);
		
		# Show the list
		if ($letterSelected) {
			$query = "SELECT DISTINCT SchArea FROM schools WHERE {$this->limitations} AND SchArea Like '{$letterSelected}%'";
			$data = $this->databaseConnection->getData ($query);
			if (!$data) {
				#!# This should be rewritten to find them out the database to avoid non-existent letters being picked in the first place
				$html .= "<p>Sorry, no areas starting with <strong>{$letterSelected}</strong> were found. Please pick another letter.</p>";
			} else {
				$links = array ();
				foreach ($data as $area) {
					$area = ucfirst ($area['SchArea']);
					$links[] = "<a href=\"locate.html?area=$area\">$area</a>";
				}
				$html .= application::htmlUl ($links);
			}
		}
		
		# Either return or echo the HTML
		if ($embedded) {
			return $html;
		} else {
			echo $html;
		}
	}
	
	
	# Function to show the list of signups
	public function signups ()
	{
		# Hand off to the schools function
		return $this->schools ($global = true);
	}
	
	
	# Logout message
	#!# Is not being routed correctly
	public function loggedout ()
	{
		echo '
		<p>You have logged out of Raven for this site.</p>
		<p>If you have finished browsing, then you should completely exit your web browser. This is the best way to prevent others from accessing your personal information and visiting web sites using your identity. If for any reason you can\'t exit your browser you should first log-out of all other personalized sites that you have accessed and then <a href="https://raven.cam.ac.uk/auth/logout.html" target="_blank">logout from the central authentication service</a>.</p>';
	}
}

?>
