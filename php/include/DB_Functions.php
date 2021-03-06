<?php   
error_reporting(E_ALL);
ini_set('display_errors', 1);
require("include/Config.php");    
 
class DB_Functions {
 
    private $sqlconn;
 
    function __construct() {
	  
		$this->sqlconn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_DATABASE);
    }
 
    function __destruct() {
         
    }
	
	public function sendMessage($id, $message) {
   
		$url = 'https://fcm.googleapis.com/fcm/send';

		$fields = array (
			'to' => $id,
			'priority' => 'high',
			'notification' => array (
					"body" => $message
			)
		);
		$fields = json_encode ( $fields );

		$headers = array (
				'Authorization: key=' . FB_API_KEY,
				'Content-Type: application/json'
		);

		$ch = curl_init ();
		curl_setopt ( $ch, CURLOPT_URL, $url );
		curl_setopt ( $ch, CURLOPT_POST, true );
		curl_setopt ( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt ( $ch, CURLOPT_POSTFIELDS, $fields );

		if( ! $result = curl_exec($ch)) 
		{ 
			trigger_error(curl_error($ch)); 
		} 
		curl_close($ch); 
		return $result; 
	}	
 
    /**
     * Storing new user
     * returns user details
     */
    public function storeUser($name, $email, $password) {
	
        $uuid = uniqid('', true);
        $hash = $this->hashSSHA($password);
        $encrypted_password = $hash["encrypted"]; // encrypted password
        $salt = $hash["salt"]; // salt
 
        $stmt = $this->sqlconn->prepare("INSERT INTO users(unique_id, name, email, encrypted_password, salt, created_at) VALUES(?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss", $uuid, $name, $email, $encrypted_password, $salt);
        $result = $stmt->execute();
        $stmt->close();
 
        // check for successful store
        if ($result) {
            $stmt = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
 
            return $user;
        } else {
            return false;
        }
    }
	
    public function storeEvent($email, $session, $name, $whenEvent, $longitude, $latitude) {  			
		$stmt_user = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
		$stmt_user->bind_param("s", $email);
		$stmt_user->execute();
		$user = $stmt_user->get_result()->fetch_assoc();
		$stmt_user->close();
		
		if (!$user) {
			return array("error" => TRUE, "error_msg" => "BAD_EMAIL");
		}
		if ($user["session"] != $session ) {
			return array("error" => TRUE, "error_msg" => "BAD_SESSION");
		}

		$uuid = uniqid('', true);	
		$userid = $user["id"];
		
		if (!$stmt = $this->sqlconn->prepare("INSERT INTO events(unique_id, name, owner, whenEvent, lastmodified, longitude, latitude) VALUES(?, ?, ?, ?, NOW(), ?, ?)")) {	
			$stmt->close();
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
		}				
				
		$stmt->bind_param("ssiidd", $uuid, $name, $userid, $whenEvent, $longitude, $latitude);
		$stmt->execute();
		$stmt->close();
		$eventid = $this->sqlconn->insert_id;
		
		$participate = $this->sqlconn->prepare("INSERT INTO participation(user_id, event_id) VALUES(?, ?)");
		$participate->bind_param("ii", $userid, $eventid);
		$participate->execute();
		$participate->close();
		
		return array("error" => FALSE, "error_msg" => "", "id" => $uuid);														
    }	
	
   public function updateEvent($email, $session, $eventid, $name, $whenEvent, $longitude, $latitude) {  			
		$stmt_user = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
		$stmt_user->bind_param("s", $email);
		$stmt_user->execute();
		$user = $stmt_user->get_result()->fetch_assoc();
		$stmt_user->close();
		
		if (!$user) {
			return array("error" => TRUE, "error_msg" => "BAD_EMAIL");
		}
		if ($user["session"] != $session ) {
			return array("error" => TRUE, "error_msg" => "BAD_SESSION");
		}
		
		$userid = $user["id"];
		
		if (!$stmt_checkOwner = $this->sqlconn->prepare("SELECT owner FROM events WHERE unique_id = ?")) {	
			$stmt->close();
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
		}		
		$stmt_checkOwner->bind_param("s", $eventid);
		$stmt_checkOwner->execute();
		$owner = $stmt_checkOwner->get_result()->fetch_assoc();
		
		if ($owner["owner"] != $userid) {
			return array("error" => TRUE, "error_msg" => "BAD_OWNER");
		}
		
		
		if (!$stmt = $this->sqlconn->prepare("UPDATE events SET name=?, whenEvent=?, lastmodified=NOW(), longitude=?, latitude=? WHERE unique_id=?")) {	
			$stmt->close();
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
		}				
			
		$bind_param = $stmt->bind_param("sidds", $name, $whenEvent, $longitude, $latitude, $eventid);
		
		if ( !$bind_param) {
			return array("error" => TRUE, "error_msg" => $stmt->error);
		}	
		
		if ( !$stmt->execute() ) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->error);
		}
							
		if ($rows = $this->sqlconn->affected_rows == -1) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->error);	
		}
		$stmt->close();	
		
		return array("error" => FALSE, "error_msg" => $rows);														
    }		
	
	public function fullSync($email, $session, $fbtoken) {
		$stmt_user = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
		$stmt_user->bind_param("s", $email);
		$stmt_user->execute();
		$user = $stmt_user->get_result()->fetch_assoc();
		$stmt_user->close();
		
		if (!$user) {
			return array("error" => TRUE, "error_msg" => "BAD_EMAIL");
		}
		if ($user["session"] != $session ) {
			return array("error" => TRUE, "error_msg" => "BAD_SESSION");
		}
		
		if (!$stmt = $this->sqlconn->prepare("SELECT unique_id AS id, name, whenEvent, (owner = ?) AS owned, longitude, latitude FROM events JOIN participation WHERE events.id = participation.event_id AND participation.user_id = ?")) {				
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
		}		
		$stmt->bind_param("ii", $user["id"], $user["id"]);
		$stmt->execute();
		$result["events"] = mysqli_fetch_all ($stmt->get_result(), MYSQLI_ASSOC);
		$stmt->close();
		
		if ($fbtoken != '') 
		{
			if (!$stmt_token = $this->sqlconn->prepare("UPDATE users SET token = ? WHERE email = ?")) {				
				return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
			}
			$stmt_token->bind_param("ss", $fbtoken, $email);
			$stmt_token->execute();
			$stmt_token->close();			
		}					
		
		if (!$stmt2 = $this->sqlconn->prepare("
			SELECT eventid, name, email, cdate, post
			FROM users 
			JOIN (
				SELECT unique_id AS eventid, user_id, cdate, post 
				FROM events 
				JOIN comments 
				ON events.id = comments.event_id) AS com 
			ON users.id = com.user_id 
			")) {				
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
		}	
		$stmt2->execute();		
				
		$result["error"] = FALSE;
		$result["error_msg"] = "";		
		$result["comments"] = mysqli_fetch_all ($stmt2->get_result(), MYSQLI_ASSOC);
		$stmt2->close();
	
		return $result;
	}
	
	public function invite($session, $email, $eventID, $emails) {
		$stmt_user = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
		$stmt_user->bind_param("s", $email);
		$stmt_user->execute();
		$user = $stmt_user->get_result()->fetch_assoc();
		$stmt_user->close();
		
		if (!$user) {
			return array("error" => TRUE, "error_msg" => "BAD_EMAIL");
		}
		if ($user["session"] != $session ) {
			return array("error" => TRUE, "error_msg" => "BAD_SESSION");
		}		
		
		if (!$stmt_event = $this->sqlconn->prepare("SELECT id, name, owner FROM events WHERE unique_id =?")) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);			
		}
		$stmt_event->bind_param("s", $eventID);
		$stmt_event->execute();
		$event = $stmt_event->get_result()->fetch_assoc();
		$stmt_event->close();
		
		if (!$event) {
			return array("error" => TRUE, "error_msg" => "BAD_EVENT");
		}
		if ($event["owner"] != $user["id"]) {
			return array("error" => TRUE, "error_msg" => "BAD_OWNER");
		}
		
		$invites = explode(",", $emails);			
		
		foreach ($invites as $invite) {
			if (!$stmt_invitee = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?")) {
				return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
			}
			$stmt_invitee->bind_param("s", $invite);
			$stmt_invitee->execute();
			$invitee = $stmt_invitee->get_result()->fetch_assoc();	
			$stmt_invitee->close();
			
			if (!$invitee) { 	//email is not registered, have to add invite to pending users
				if(!$stmt_existinginv = $this->sqlconn->prepare("SELECT * FROM pending_invites WHERE user_id = ? AND event_id = ?")) {
					return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
				}
				$stmt_existinginv->bind_param("ii", $invitee["id"], $event["id"]);
				$stmt_existinginv->execute();
				$existing = $stmt_existinginv->get_result()->fetch_assoc();
				$stmt_existinginv->close();
				if (!$existing) {
					if(!$stmt_existinguser = $this->sqlconn->prepare("SELECT * FROM pending_users WHERE email = ?")) {
						return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
					}
					$stmt_existinguser->bind_param("s", $invite);
					$stmt_existinguser->execute();
					$puser = $stmt_existinguser->get_result()->fetch_assoc();
					$stmt_existinguser->close();
					
					if (!$puser) {
							if(!$stmt_newuser = $this->sqlconn->prepare("INSERT INTO pending_users(email) VALUES (?)")) {
								return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);					
							}
							$stmt_newuser->bind_param("s", $invite);
							$stmt_newuser->execute();
							$stmt_newuser->close();
							$puserid = $this->sqlconn->insert_id;
						}
						else {
							$puserid = $puser["id"];
						}
						if(!$stmt_add = $this->sqlconn->prepare("INSERT INTO pending_invites(user_id, event_id) VALUES (?, ?)")) {
							return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);					
						}
						$stmt_add->bind_param("ii", $puserid, $event["id"]);
						$stmt_add->execute();
						$stmt_add->close();
						return array("error" => FALSE, "error_msg" => "");						
					}
				
			} else {	//user already registered							
				if(!$stmt_existinginv = $this->sqlconn->prepare("SELECT * FROM participation WHERE user_id = ? AND event_id = ?")) {
					return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);
				}
				$stmt_existinginv->bind_param("ii", $invitee["id"], $event["id"]);
				$stmt_existinginv->execute();
				$existing = $stmt_existinginv->get_result()->fetch_assoc();
				$stmt_existinginv->close();
				if (!$existing) {
					if(!$stmt_add = $this->sqlconn->prepare("INSERT INTO participation(user_id, event_id) VALUES (?, ?)")) {
						return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);					
					}
					$stmt_add->bind_param("ii", $invitee["id"], $event["id"]);
					$stmt_add->execute();
					$stmt_add->close();
					if ($invitee["token"] != null) { //if not null, try to send push notification
						$nres = $this->sendMessage($invitee["token"], $user["name"] . " has invited you to " . $event["name"]);
					}					
					
				}
				return array("error" => FALSE, "error_msg" => "" . $nres);
				
			}
			
			
			return array("error" => TRUE, "error_msg" => "UNEXPECTED_ERROR" . $user["id"]. " ". $event["id"] . " " );
		}		
	}	
 
    /**
     * Get user by email and password
     */
    public function getUserByEmailAndPassword($email, $password) {
 
        $stmt = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
 
        $stmt->bind_param("s", $email);
 
        if ($stmt->execute()) {
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
 
            // verifying user password
            $salt = $user['salt'];
            $encrypted_password = $user['encrypted_password'];
            $hash = $this->checkhashSSHA($salt, $password);
            // check for password equality
            if ($encrypted_password == $hash) {
                // user authentication details are correct
				$session = uniqid('session_', true);
				$stmt2 = $this->sqlconn->prepare("UPDATE users SET session = ? WHERE email = ?");
				$stmt2->bind_param("ss", $session, $email);
				$stmt2->execute();
				$stmt2->close();
				$user["session"] = $session;
                return $user;
            }
        } else {
            return NULL;
        }
    }
 
    /**
     * Check user is existed or not
     */
    public function isUserExisted($email) {
        $stmt = $this->sqlconn->prepare("SELECT email from users WHERE email = ?");
 
        $stmt->bind_param("s", $email);
 
        $stmt->execute();
 
        $stmt->store_result();
 
        if ($stmt->num_rows > 0) {
            // user existed 
            $stmt->close();
            return true;
        } else {
            // user not existed
            $stmt->close();
            return false;
        }
    }
 
    /**
     * Encrypting password
     * @param password
     * returns salt and encrypted password
     */
    public function hashSSHA($password) {
 
        $salt = sha1(rand());
        $salt = substr($salt, 0, 10);
        $encrypted = base64_encode(sha1($password . $salt, true) . $salt);
        $hash = array("salt" => $salt, "encrypted" => $encrypted);
        return $hash;
    }
 
    /**
     * Decrypting password
     * @param salt, password
     * returns hash string
     */
    public function checkhashSSHA($salt, $password) {
 
        $hash = base64_encode(sha1($password . $salt, true) . $salt);
 
        return $hash;
    }
	
	public function storeComment($email, $session, $eventID, $post) {
		$stmt_user = $this->sqlconn->prepare("SELECT * FROM users WHERE email = ?");
		$stmt_user->bind_param("s", $email);
		$stmt_user->execute();
		$user = $stmt_user->get_result()->fetch_assoc();
		$stmt_user->close();
		
		//Check if user exists
		if (!$user) {
			return array("error" => TRUE, "error_msg" => "BAD_EMAIL");
		}
		if ($user["session"] != $session ) {
			return array("error" => TRUE, "error_msg" => "BAD_SESSION");
		}		
		
		//Check if event exists
		if (!$stmt_event = $this->sqlconn->prepare("SELECT id FROM events WHERE unique_id =?")) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);			
		}
		$stmt_event->bind_param("s", $eventID);
		$stmt_event->execute();
		$event = $stmt_event->get_result()->fetch_assoc();
		$stmt_event->close();
		
		if (!$event) {
			return array("error" => TRUE, "error_msg" => "BAD_EVENT");
		}
		
		//Check if the user is invited 
		if (!$stmt_partic = $this->sqlconn->prepare("SELECT * FROM participation WHERE user_id = ? AND event_id = ?")) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);			
		}
		$stmt_partic->bind_param("ii", $user["id"], $event["id"]);
		$stmt_partic->execute();
		$invited = $stmt_partic->get_result()->fetch_assoc();
		$stmt_partic->close();
		
		if (!$invited) {
			return array("error" => TRUE, "error_msg" => "BAD_PARTICIPATION");
		}
		
		//add comment
		if (!$stmt_comment = $this->sqlconn->prepare("INSERT INTO comments(user_id, event_id, cdate, post) VALUES (?, ?, UNIX_TIMESTAMP()*1000, ?)")) {
			return array("error" => TRUE, "error_msg" => $this->sqlconn->errno . ' ' . $this->sqlconn->error);			
		}		
		$stmt_comment->bind_param("iis", $user["id"], $event["id"], $post);
		$result = $stmt_comment->execute();
		$stmt_comment->close();	
		if ($result) {
			return array("error" => FALSE, "error_msg" => "");
		}
		return array("error" => TRUE, "error_msg" => "BAD_SOMETHING");		
	}
	

}
 
?>