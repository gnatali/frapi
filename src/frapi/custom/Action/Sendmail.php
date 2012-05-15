<?php

require_once('Mail.php');
require_once('Mail/mime.php');
require_once('Mail/RFC822.php');


/**
 * Action Sendmail 
 * 
 * Envoyé un mail
 * 
 * @link http://getfrapi.com
 * @author Frapi <frapi@getfrapi.com>
 * @link sendmail/1
 */
class Action_Sendmail extends Frapi_Action implements Frapi_Action_Interface
{    
	const ERROR_SERVICE_UNAVAILABLE_NO         = 410; // Malformed.
	const ERROR_EMAIL_INVALID_NO               = 411;
	const ERROR_SENDING_EMAIL_NO			   = 412;
	
    const ERROR_SERVICE_UNAVAILABLE_MSG        = 'Le service demandé n\'est pas/plus disponible';
	const ERROR_EMAIL_INVALID_MSG              = 'Une ou plusieurs emails semblent invalides';
	const ERROR_SENDING_EMAIL_MSG              = 'Impossible d\'envoyer le mail';
	
    const ERROR_SERVICE_UNAVAILABLE_NAME       = 'ERROR_SERVICE_UNAVAILABLE';
	const ERROR_EMAIL_INVALID_NAME             = 'ERROR_EMAIL_INVALID';
	const ERROR_SENDING_EMAIL_NAME             = 'ERROR_SENDING_EMAIL';
	
	const ERROR_EMAIL_INVALID_LABEL            = 'Email(s) non valide : %s';
	const ERROR_SENDING_EMAIL_LABEL            = 'Errer : %s';

	const MAX_EMAIL	= 20;

    public $db;
    
    /**
     * Required parameters
     * 
     * @var An array of required parameters.
     */
    protected $requiredParams = array(
        'from',
        'to',
		'subject',
        'body'
        );

    /**
     * The data container to use in toArray()
     * 
     * @var A container of data to fill and return in toArray()
     */
    private $data = array();

    public function __construct()
    {
       // $this->db = Frapi_Database::getInstance();
	   
    }
	
	function check_email($email) {
        if(preg_match('/^\w[-.\w]*@(\w[-._\w]*\.[a-zA-Z]{2,}.*)$/', $email, $matches))
        {
            if(function_exists('checkdnsrr'))
            {
                if(checkdnsrr($matches[1] . '.', 'MX')) return true;
                if(checkdnsrr($matches[1] . '.', 'A')) return true;
            }else{
                if(!empty($matches[1]))
                {	
                    exec("nslookup -type=MX ${matches[1]}", $result);
                    foreach ($result as $line)
                    {
                        if(eregi("^${matches[1]}",$line))
                        {
                            return true;
                        }
                    }
                    return false;
                }
                return false;
            }
        }
        return false;
    }
	
	private function checkEmailList($param) {
		$emails = trim($this->getParam($param,self::TYPE_STRING));
		$valide=array();
		$invalide=array();
		if ($emails <> '') {
			$addresses = Mail_RFC822::parseAddressList($emails);
			foreach ($addresses as $add) {
				if ($this->check_email($add->mailbox.'@'.$add->host))
					$valide[] = $add->personal.' <'.$add->mailbox.'@'.$add->host.'>';
				else
					$invalide[] = $add->personal.' <'.$add->mailbox.'@'.$add->host.'>';
					
			}
		}
		
		if (empty($valide)) 
			unset($this->Param[$param]);
		else
			$this->Param[$param] = implode(',',$valide);
		
		if (!empty($invalide)) {
			throw new Frapi_Action_Exception (
                Action_Sendmail::ERROR_EMAIL_INVALID_MSG,
                Action_Sendmail::ERROR_EMAIL_INVALID_NAME,
                Action_Sendmail::ERROR_EMAIL_INVALID_NO,
                sprintf(Action_Sendmail::ERROR_EMAIL_INVALID_LABEL, htmlentities(implode(',',$invalide))),
                400
            );			
		}
		
		return true;
	}
	
    /**
     * To Array
     * 
     * This method returns the value found in the database 
     * into an associative array.
     * 
     * @return array
     */
    public function toArray()
    {
        return array('sendmail'=>$this->data);
    }

	public function toResult()
	{
		   return array('sendmail'=>array('result'=>$this->data));
	}
    /**
     * Default Call Method
     * 
     * This method is called when no specific request handler has been found
     * 
     * @return array
     */
    public function executeAction()
    {
        $valid = $this->hasRequiredParameters($this->requiredParams);
        if ($valid instanceof Frapi_Error) {
            return $valid;
        }
        
        return $this->toArray();
    }
	
	private function return_bytes($val) {
		$val = trim($val);
		$last = strtolower($val[strlen($val)-1]);
		switch($last) {
			// The 'G' modifier is available since PHP 5.1.0
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}

		return $val;
	}
	
    /**
     * Get Request Handler
     * 
     * This method is called when a request is a GET
     * 
     * @return array
     */
    public function executeGet()
    {
	//post_max_size : taille max de post
	//upload_max_filesize  : taille max des fichiers uploadé
	//max_file_uploads : nombre max de fichier
	//file_uploads : autorise l'upload de fichier
	
		$this->data = array('config'=>array(
			'required' => implode(',',$this->requiredParams),
			'post_max_size' => $this->return_bytes(ini_get('post_max_size')),
			'upload_max_filesize' => $this->return_bytes(ini_get('upload_max_filesize')),
			'max_file_uploads' => ini_get('max_file_uploads'),
			'file_uploads' => ((ini_get('file_uploads') && (ini_get('max_file_uploads')>0))?'true':'false'),
			'max_email' => Action_Sendmail::MAX_EMAIL)
		);
		
	//throw new Frapi_Error('UNAVAILABLE');    
	//$valid = $this->hasRequiredParameters($this->requiredParams);
        //if ($valid instanceof Frapi_Error) {
        //    return $valid;
        //}
        
		return $this->toArray();
    }

    /**
     * Post Request Handler
     * 
     * This method is called when a request is a POST
     * 
     * @return array
     */
    public function executePost()
    {
		
		$this->checkEmailList('from');
		$this->checkEmailList('to');
		$this->checkEmailList('cc');
		$this->checkEmailList('bcc');
		
		$this->params['subject'] = trim($this->getParam('subject',self::TYPE_STRING));
		$this->params['body'] = trim($this->getParam('body',self::TYPE_STRING));
		$this->params['bodyhtml'] = trim($this->getParam('bodyhtml',self::TYPE_STRING));
		if ($this->params['subject'] == '') $this->params['subject'] = null;
		if ($this->params['body'] == '') $this->params['body'] = null;
		if ($this->params['bodyhtml'] == '') $this->params['bodyhtml'] = null;

		
		$valid = $this->hasRequiredParameters($this->requiredParams);
		
		
        
		$hdrs = array (
			'From' => $this->params['from'],
			'Subject' => $this->params['subject']
			);
		if (isset($this->params['cc'])) $hdrs['Cc'] = $this->params['cc'];
		if (isset($this->params['bcc'])) $hdrs['Bcc'] = $this->params['bcc'];
		
		$mime = new Mail_mime("\n");
		$mime->setTxtBody($this->params['body']);
		$mime->setHTMLBody($this->params['bodyhtml']);
		
		$files = $this->getFiles();
		foreach ($files as $file) {
			if (($file['size']!==0)&&($file['name']!=='')) {
				$mime->addAttachment($file['tmp_name'],'application/octet-stream', $file['name']);
			}
		}
		$body = $mime->get();
		$hdrs = $mime->headers($hdrs);
		
		$mail =& Mail::factory('mail');
		$send = $mail->send($this->params['to'],$hdrs,$body);
		
		if (PEAR::isError($send)) { 
			throw new Frapi_Action_Exception (
                Action_Sendmail::ERROR_SENDING_EMAIL_MSG,
                Action_Sendmail::ERROR_SENDING_EMAIL_MSG,
                Action_Sendmail::ERROR_SENDING_EMAIL_NO,
                sprintf(Action_Sendmail::ERROR_SENDING_EMAIL_LABEL, htmlentities($send->getMessage())),
                400);
		} 
        
		$this->data = array(
			'message'=>'Mail envoyé correctement'
			);
		
		
		
        return $this->toResult();
    }

    /**
     * Put Request Handler
     * 
     * This method is called when a request is a PUT
     * 
     * @return array
     */
    public function executePut()
    {
	throw new Frapi_Error('UNAVAILABLE');
	//$valid = $this->hasRequiredParameters($this->requiredParams);
        //if ($valid instanceof Frapi_Error) {
        //    return $valid;
       // }
        
        //return $this->toArray();
    }

    /**
     * Delete Request Handler
     * 
     * This method is called when a request is a DELETE
     * 
     * @return array
     */
    public function executeDelete()
    {
	throw new Frapi_Error('UNAVAILABLE');
	//$valid = $this->hasRequiredParameters($this->requiredParams);
        //if ($valid instanceof Frapi_Error) {
        //    return $valid;
        //}
        
        //return $this->toArray();
    }

    /**
     * Head Request Handler
     * 
     * This method is called when a request is a HEAD
     * 
     * @return array
     */
    public function executeHead()
    {
	throw new Frapi_Error('UNAVAILABLE');
	//    $valid = $this->hasRequiredParameters($this->requiredParams);
        //if ($valid instanceof Frapi_Error) {
        //    return $valid;
        //}
        
        //return $this->toArray();
    }


}
