<?php
class Core_Api_Controller extends Zend_Controller_Action
{
    const HTTP_STATUS_CODE_OK = 200;
    const HTTP_STATUS_CODE_CREATED = 201;
    const HTTP_STATUS_CODE_NOT_MODIFIED = 304;
    const HTTP_STATUS_CODE_FORBIDDEN = 403;
    const HTTP_STATUS_CODE_BAD_REQUEST = 400;
    const HTTP_STATUS_CODE_NOT_FOUND = 404;
    const HTTP_STATUS_CODE_METHOD_NOT_ALLOWED = 405;
    const HTTP_STATUS_CODE_INTERNAL_ERROR = 500;
    const HTTP_STATUS_CODE_NOT_IMPLEMENTED = 501;

    protected static $_methodsActionsMap = array(
        'index'  => 'list',
        'get'    => 'get',
        'post'   => 'create',
        'put'    => 'update',
        'delete' => 'delete',
    );

    /**
     * API version
     *
     * returned with response
     * @var string
     */
    protected $_version = '0.2';

    protected $_isDebug = false;
    /**
     * @var Model_User
     */
    protected $_currUser = null;

    /**
     * If this resource is accessible only for authenticated users or not
     * @var boolean
     */
    protected $_secured = true;

    /**
     * Unsecured Actions List
     *
     * Separate unsecured actions for the case when only some actions of secured resource are need to be accessible for the unathenticated users
     * @var array
     */
    protected $_unsecuredActionsList = array();

    /**
     * Rest specific request data
     *
     * @var array
     */
    protected $_requestData = null;

    /**
     * Current REST resource
     * @var string
     */
    protected $_resource = null;

    /**
     * Current REST action
     * @var string
     */
    protected $_action = null;

    /**
     * Data to be output as JSON response
     *
     * @var array
     */
    protected $_data = array();

    /**
     * Data to be appended to output when debug mode is on
     *
     * @var array
     */
    protected $_dataToDebug = array();

    /**
     * Headers to be sent with response
     *
     * @var array
     */
    protected $_headers = array('Content-Type' => 'application/json');

    /**
     * Entity since modified
     *
     * This var contains the date of last entity state on client. should be processed as cache mechanizm by date
     * @var string
     */
    protected $_ifModifiedSinceDate = null;

    /**
     * Allowed actions
     *
     * List of implemented actions for the particular resource.
     * Default to array('index', 'get', 'post', 'put', 'delete')
     *
     * @var array
     */
    protected $_allowedActions = array();
    
    /**
     * If action handled succesfully or wit errors
     *
     * @var boolean
     */
    protected $_success = true;

    /**
     * HTTP Status Code of the result response
     * @var integer
     */
    protected $_statusCode = self::HTTP_STATUS_CODE_OK;

    public function init()
    {
        Zend_Controller_Action_HelperBroker::getStaticHelper('Layout')->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
    }

    /**
     * Rest Resource entry point
     * 
     * @param string $action
     */
    public function dispatch($action)
    {
        try {
            $this->_parseRequestData();
            if ($this->_allowedActions && !in_array($this->_action, $this->_allowedActions)) {
                $mappedAction = isset(self::$_methodsActionsMap[$this->_action]) ? self::$_methodsActionsMap[$this->_action] : $this->_action;
                throw new Core_Api_Exception("Action '$mappedAction' is not implemented for the resource $this->_resource",
                    self::HTTP_STATUS_CODE_METHOD_NOT_ALLOWED);
            }
            $this->_getCurrentUser();
            $this->_checkAuthentication();
            $this->_preActionMethodExecute();
            parent::dispatch($action);         //execute the action here
        } catch (Core_Api_Form_Exception $e) { //normal driven exception for forms validation
            $this->_fault($e->getMessages(), $e->getCode(), $e->getResourceName());
        } catch (Core_Api_Exception $e) {      //normal driven exception
            $this->_fault($e->getMessage(), $e->getCode());
        } catch (Exception $e) {               //internal exception - very bad, needs to log
            $this->_fault(getenv('APPLICATION_ENV') == 'development' ? $e : 'Internal Error', self::HTTP_STATUS_CODE_INTERNAL_ERROR);
            Core_App::log(array(
                'exception' => $e,
                'request'   => $this->getRequest(),
                'user'      => $this->_currUser ? $this->_currUser->toArray() : null,
                'ip'        => $_SERVER['REMOTE_ADDR']
            ), Core_App::LOG_API_ERROR);
        }
        $this->_renderOutput();
    }

    protected function _parseRequestData()
    {
        $this->_action = $this->_getParam('action');
        $this->_resource = str_replace(array('Api_', 'Controller'), '', get_class($this));
        $this->_requestData = $this->_getAllParams();
        $this->_isDebug = !empty($this->_requestData['debug']) && getenv('APPLICATION_ENV') == 'development';
        unset($this->_requestData['module'], $this->_requestData['controller'], $this->_requestData['action'], $this->_requestData['debug']);
        if (null != ($dateRaw = $this->getRequest()->getHeader('If-Modified-Since'))) {
            $this->_ifModifiedSinceDate = date('Y-m-d H:i:s', strtotime($dateRaw));
        }
    }

    protected function _getParamRequired($paramName, $default = null)
    {
        if (null == ($value = parent::_getParam($paramName, $default))) {
            throw new Core_Api_Exception('Wrong rerquest: missing parameter: ' . $paramName, self::HTTP_STATUS_CODE_BAD_REQUEST);
        }
        return $value;
    }

    protected function _preActionMethodExecute()
    {
        //logic is overriden in children classes
    }

    protected function _authenticate($email, $password)
    {
        $authAdapter = new Ext_Auth_Adapter_Doctrine('Model_User', 'email');
        $authAdapter->setIdentity($email);
        $authAdapter->setCredential($password);

        if (!Core_Auth::getInstance()->useApiStorage()->authenticate($authAdapter)->isValid()) {
            throw new Core_Api_Exception('Invalid email or password', self::HTTP_STATUS_CODE_FORBIDDEN);
        }
    }

    protected function _getCurrentUser()
    {
        if (null != ($userId = Core_Auth::getInstance()->useApiStorage()->getIdentity())) {
            $this->_currUser = Model_UserTable::getInstance()->findOneByEmail($userId);
        }
    }

    protected function _checkAuthentication()
    {
        if (($this->_secured && !$this->_currUser && !in_array($this->_action, $this->_unsecuredActionsList)) && !$this->_currUser) {
            throw new Core_Api_Exception('Not Authorized', self::HTTP_STATUS_CODE_FORBIDDEN);
        }
    }

    protected function _fault($error, $httpStatusCode, $resourceName = null)
    {
        if ($resourceName) {
            //this is form validation errors
            $this->_data = array(
                'error' => 'Resource validation error',
                'validation_errors' => array($resourceName => $error)
            );
        } else {
            $this->_data = array('error' => $error instanceof Exception ? $error->getMessage() : $error);
        }
        if ($this->_isDebug && $error instanceof Exception) {
            $this->_data['exceptionTrace'] = $error->getTrace();
        }
        $this->_statusCode = $httpStatusCode;
        $this->_success = false;
    }

    protected function _renderOutput()
    {
        $response = $this->getResponse();
        $response->setHttpResponseCode($this->_statusCode);
        foreach ($this->_headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        $action = isset(self::$_methodsActionsMap[$this->_action]) ? self::$_methodsActionsMap[$this->_action] : $this->_action;
        $this->_data = array(
            'version' => $this->_version,
            'success' => $this->_success,
            'rest_action' => "{$this->_resource}::{$action}",
        ) + $this->_data;
        if ($this->_isDebug) {
            $this->_data['debug'] = array('request_params' => $this->_requestData);
            if ($this->_dataToDebug) {
                $this->_data['debug']['data'] = $this->_dataToDebug;
            }
        }
        $response->setBody(json_encode($this->_data));
    }
}