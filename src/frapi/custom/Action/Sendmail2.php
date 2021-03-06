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
	const ERROR_SERVICE_UNAVAILABLE_NO			= 410; // Malformed.
	const ERROR_EMAIL_INVALID_NO				= 411;
	const ERROR_SENDING_EMAIL_NO				= 412;
	const ERROR_MAX_EMAIL_NO					= 413;
	const ERROR_CLASSE_NO						= 414;

	const ERROR_SERVICE_UNAVAILABLE_MSG			= 'Le service demandé n\'est pas/plus disponible';
	const ERROR_EMAIL_INVALID_MSG				= 'Une ou plusieurs emails semblent invalides';
	const ERROR_SENDING_EMAIL_MSG				= 'Impossible d\'envoyer le mail';
	const ERROR_MAX_EMAIL_MSG				   	= 'Nombre maximum de destinataire dépassé (%d>%d)';
	const ERROR_CLASSE_MSG						= 'Reponse mal formée, la classe n\'est pas définie';
	
	const ERROR_SERVICE_UNAVAILABLE_NAME		= 'ERROR_SERVICE_UNAVAILABLE';
	const ERROR_EMAIL_INVALID_NAME				= 'ERROR_EMAIL_INVALID';
	const ERROR_SENDING_EMAIL_NAME				= 'ERROR_SENDING_EMAIL';
	const ERROR_MAX_EMAIL_NAME					= 'ERROR_MAX_EMAIL_NAME';
	const ERROR_CLASSE_NAME						= 'ERROR_CLASSE_NAME';

	const ERROR_EMAIL_INVALID_LABEL				= 'Email(s) non valide : %s';
	const ERROR_SENDING_EMAIL_LABEL				= 'Erreur : %s';

	const MAX_EMAIL = 20;

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

	/**
	 * Indique si l'on est en mode debug
	 *
	 * @var si vrai, aucun mail n'est envoyé.
	 */
	private $debug = false;
	
    /**
     * The data container to use in toArray()
     *
     * @var A container of data to fill and return in toArray()
     */
	private $warnings = array();
	
	/**
	 * nom de la classe reponse
	 **/
	private $classe = null;
	
	/**
	 * log de debug
	 **/
	private $_log = array();
	
    public function __construct()
    {
       // $this->db = Frapi_Database::getInstance();

    }

    private function check_email($email) {
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

	private function checkEmailList($param, $list_emails = NULL, $raise_empty = false) {
		$emails = trim(quoted_printable_decode($this->getParam($param,self::TYPE_STRING)));
		$this->log("Analyse des emails '$param' : $emails");
		$valide=array();
		$invalide=array();
		if ($emails <> '') {
			$addresses = Mail_RFC822::parseAddressList($emails);
			if (! is_array($addresses)) {
				if (is_object($addresses) && ($addresses instanceof PEAR_Error)) {
					throw new Frapi_Action_Exception (
                                Action_Sendmail::ERROR_EMAIL_INVALID_MSG,
                                Action_Sendmail::ERROR_EMAIL_INVALID_NAME,
                                Action_Sendmail::ERROR_EMAIL_INVALID_NO,
                                htmlentities(sprintf(Action_Sendmail::ERROR_EMAIL_INVALID_LABEL,$addresses->getMessage() )." donnees sources : $emails"),
                                400);
 
				}
				else
					$this->log('Adresses n est pas un tableau : '.gettype($addresses).get_class($addresses));
			}
			else {
				foreach ($addresses as $add) {
					if (! is_object($add)) {
						$this->log('Erreur sur : '.$add);
					}
					else {
						if ($this->check_email($add->mailbox.'@'.$add->host))
							$valide[] = $add->personal.' <'.$add->mailbox.'@'.$add->host.'>';
						else
							$invalide[] = $add->personal.' <'.$add->mailbox.'@'.$add->host.'>';
					}
				}
			}
		}

		if (empty($valide)) {
			unset($this->params[$param]);
			$this->log($param.' est invalide : '.implode(',',$invalide));
		}
		else {
			$this->params[$param] = implode(',',$valide);
			$this->log($param.' est valide : '.implode(',',$valide));
		}
			
		if ( (is_null($list_emails)&&(!empty($invalide))) ||
			 ($raise_empty && empty($valide)) ) {
			throw new Frapi_Action_Exception (
				Action_Sendmail::ERROR_EMAIL_INVALID_MSG,
				Action_Sendmail::ERROR_EMAIL_INVALID_NAME,
				Action_Sendmail::ERROR_EMAIL_INVALID_NO,
				htmlentities(sprintf(Action_Sendmail::ERROR_EMAIL_INVALID_LABEL, implode(',',$invalide))),
				400
			);
		}

		if (is_null($list_emails)) $list_emails = array();
		if (! isset($list_emails['valide'])) $list_emails['valide'] = array();
		if (! isset($list_emails['invalide'])) $list_emails['invalide'] = array();
		
		$list_emails['valide'] = array_merge($list_emails['valide'],$valide);
		$list_emails['invalide'] = array_merge($list_emails['invalide'],$invalide);
		
		return $list_emails;
	}
	
	
	private function setClasse($classe) {
		$this->classe = $classe;
	}
	

	private function addData($name, $value) {
		if (is_array($value)) $value = implode(',',$value);
		
		$this->data[$name] = $value;
	}
	
	private function addWarning($name, $num, $values) {
		if (is_array($values)) $values=implode(',',$values);
		
		$this->warnings[] = array('warning'=>
			array(
				'name'=>$name,
				'numero'=>$num,
				'values'=>htmlentities($values)));
	}
	
	private function log($message) {
		if ($this->debug) {
			$this->_log[] = $message;
		}
	}
	
	private function setMessage($message) {
		$this->addData('message',htmlentities($message));
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
		if ( ! $this->classe ) {
			throw new Frapi_Action_Exception (
				Action_Sendmail::ERROR_CLASSE_MSG,
				Action_Sendmail::ERROR_CLASSE_MSG,
				Action_Sendmail::ERROR_CLASSE_NO,
				Action_Sendmail::ERROR_CLASSE_MSG,
				400);		
		}
		
		$result = $this->data;
	
		if (count($this->warnings))
			$result['warnings'] = $this->warnings;
		if ($this->debug) {
			$result['debug'] = true;
			$result['log'] = implode("\n",$this->_log);
		}
			
		return array('sendmail'=>array($this->classe => $result));
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
		
		$this->addData('required',$this->requiredParams);		
		$this->addData('post_max_size',$this->return_bytes(ini_get('post_max_size')));
		$this->addData('upload_max_filesize',$this->return_bytes(ini_get('upload_max_filesize')));
		$this->addData('max_file_uploads',ini_get('max_file_uploads'));
		$this->addData('file_uploads',((ini_get('file_uploads') && (ini_get('max_file_uploads')>0))?'true':'false'));
		$this->addData('max_email',Action_Sendmail::MAX_EMAIL);
		
		$this->setClasse('config');
		
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
		if (isset($this->params['debug'])) $this->debug = true;
		
		$emails = array();
		

		$this->checkEmailList('from');
		
		$emails = $this->checkEmailList('to', $emails, true);
		$emails = $this->checkEmailList('cc', $emails);
		$emails = $this->checkEmailList('bcc',$emails);

		
		$this->params['subject'] = trim(quoted_printable_decode($this->getParam('subject',self::TYPE_STRING)));
		$this->params['body'] = trim(quoted_printable_decode($this->getParam('body',self::TYPE_STRING)));
		$this->params['bodyhtml'] = trim(quoted_printable_decode($this->getParam('bodyhtml',self::TYPE_STRING)));
		if ($this->params['subject'] == '') $this->params['subject'] = null;
		if ($this->params['body'] == '') $this->params['body'] = null;
		if ($this->params['bodyhtml'] == '') $this->params['bodyhtml'] = null;


		$valid = $this->hasRequiredParameters($this->requiredParams);

		// verification de nombre maximum de mail
		$emails['valide'] = array_unique($emails['valide']);
		if (count($emails['valide'])>Action_Sendmail::MAX_EMAIL) {
			throw new Frapi_Action_Exception (
				Action_Sendmail::ERROR_MAX_EMAIL_MSG,
				Action_Sendmail::ERROR_MAX_EMAIL_MSG,
				Action_Sendmail::ERROR_MAX_EMAIL_NO,
				sprintf(Action_Sendmail::ERROR_MAX_EMAIL_MSG,count($emails['valide']),Action_Sendmail::MAX_EMAIL) ,
				400);		
		}
		
		//
		$emails['invalide'] = array_unique($emails['invalide']);
		if (count($emails['invalide'])>0) {
			$this->AddWarning(
				Action_Sendmail::ERROR_EMAIL_INVALID_NAME,
				Action_Sendmail::ERROR_EMAIL_INVALID_NO,
				$emails['invalide']);
		}


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
		
		if (!$this->debug) {
			$send = $mail->send($this->params['to'],$hdrs,$body);

			if (PEAR::isError($send)) {
				throw new Frapi_Action_Exception (
					Action_Sendmail::ERROR_MAX_EMAIL_MSG,
					Action_Sendmail::ERROR_MAX_EMAIL_MSG,
					Action_Sendmail::ERROR_MAX_EMAIL_NO,
					sprintf(Action_Sendmail::ERROR_SENDING_EMAIL_LABEL, htmlentities($send->getMessage())),
					400);
			}
		}

		$this->setMessage('Mail envoyé correctement');
		$this->setClasse('result');
		
        return $this->toArray();
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
    }


}

class Action_Sendmail2 extends Action_Sendmail {
}
