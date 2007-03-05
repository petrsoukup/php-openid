<?php

require_once 'PHPUnit.php';
require_once 'TestUtil.php';

require_once 'Auth/OpenID.php';
require_once 'Auth/OpenID/Discover.php';
require_once 'Services/Yadis/Manager.php';
require_once 'Services/Yadis/Misc.php';
require_once 'Services/Yadis/XRI.php';

/**
 * Tests for the core of the PHP Yadis library discovery logic.
 */

class _SimpleMockFetcher {
    function _SimpleMockFetcher($responses)
    {
        $this->responses = $responses;
    }

    function get($url)
    {
        $response = array_pop($this->responses);
        assert($response[1] == $url);
        return $response;
    }
}

class Tests_Auth_OpenID_DiscoveryFailure extends PHPUnit_TestCase {

    function Tests_Auth_OpenID_DiscoveryFailure($responses)
    {
        // Response is ($code, $url, $body).
        $this->cases = array(
                             array(null, 'http://network.error/', ''),
                             array(404, 'http://not.found/', ''),
                             array(400, 'http://bad.request/', ''),
                             array(500, 'http://server.error/', ''),
                             array(200, 'http://header.found/', 200,
                                   array('x-xrds-location' => 'http://xrds.missing/')),
                             array(404, 'http://xrds.missing/', ''));

        $this->url = $responses[0]->final_url;
        $this->responses = $responses;
        $this->fetcher = new _SimpleMockFetcher($this->responses);
    }

    function runTest()
    {
        foreach ($this->cases as $case) {
            list($status, $url, $body) = $case;
            $expected_status = $status;

            $result = Auth_OpenID_discover($this->url, $this->fetcher);
            list($id_url, $svclist) = $result;

            $this->assertEquals($svclist, array());
        }
    }
}

### Tests for raising/catching exceptions from the fetcher through the
### discover function

class _ErrorRaisingFetcher {
    // Just raise an exception when fetch is called

    function _ErrorRaisingFetcher($thing_to_raise)
    {
        $this->thing_to_raise = $thing_to_raise;
    }

    function post($body = null)
    {
        __raiseError($this->thing_to_raise);
    }

    function get($url)
    {
        __raiseError($this->thing_to_raise);
    }
}

define('E_AUTH_OPENID_EXCEPTION', 'e_exception');
define('E_AUTH_OPENID_DIDFETCH', 'e_didfetch');
define('E_AUTH_OPENID_VALUE_ERROR', 'e_valueerror');
define('E_AUTH_OPENID_RUNTIME_ERROR', 'e_runtimeerror');
define('E_AUTH_OPENID_OI', 'e_oi');

class Tests_Auth_OpenID_Discover_FetchException extends PHPUnit_TestCase {
    // Make sure exceptions get passed through discover function from
    // fetcher.

    function Tests_Auth_OpenID_Discover_FetchException($exc)
    {
        $this->cases = array(E_AUTH_OPENID_EXCEPTION,
                             E_AUTH_OPENID_DIDFETCH,
                             E_AUTH_OPENID_VALUE_ERROR,
                             E_AUTH_OPENID_RUNTIME_ERROR,
                             E_AUTH_OPENID_OI);
    }

    function runTest()
    {
        foreach ($this->cases as $thing_to_raise) {
            $fetcher = ErrorRaisingFetcher($thing_to_raise);
            Auth_OpenID_discover('http://doesnt.matter/', $fetcher);
            $exc = __getError();

            if ($exc !== $thing_to_raise) {
                $this->fail('FetchException expected %s to be raised',
                            $thing_to_raise);
            }
        }
    }
}


// Tests for openid.consumer.discover.discover

class _DiscoveryMockFetcher {
    function _DiscoveryMockFetcher(&$documents)
    {
        $this->redirect = null;
        $this->documents = &$documents;
        $this->fetchlog = array();
    }

    function post($url, $body = null, $headers = null)
    {
        return $this->get($url, $headers, $body);
    }

    function get($url, $headers = null, $body = null)
    {
        $this->fetchlog[] = array($url, $body, $headers);

        if ($this->redirect) {
            $final_url = $this->redirect;
        } else {
            $final_url = $url;
        }

        if (array_key_exists($url, $this->documents)) {
            list($ctype, $body) = $this->documents[$url];
            $status = 200;
        } else {
            $status = 404;
            $ctype = 'text/plain';
            $body = '';
        }

        return new Services_Yadis_HTTPResponse($final_url, $status,
                                               array('content-type' => $ctype), $body);
    }
}

class _DiscoveryBase extends PHPUnit_TestCase {
    var $id_url = "http://someuser.unittest/";
    var $fetcherClass = '_DiscoveryMockFetcher';

    function _checkService($s,
                           $server_url,
                           $claimed_id=null,
                           $local_id=null,
                           $canonical_id=null,
                           $types=null,
                           $used_yadis=false)
    {
        $this->assertEquals($server_url, $s->server_url);
        if ($types == array('2.0 OP')) {
            $this->assertFalse($claimed_id);
            $this->assertFalse($local_id);
            $this->assertFalse($s->claimed_id);
            $this->assertFalse($s->local_id);
            $this->assertFalse($s->getLocalID());
            $this->assertFalse($s->compatibilityMode());
            $this->assertTrue($s->isOPIdentifier());
            $this->assertEquals($s->preferredNamespace(),
                                Auth_OpenID_TYPE_2_0);
        } else {
            $this->assertEquals($claimed_id, $s->claimed_id);
            $this->assertEquals($local_id, $s->getLocalID());
        }

        if ($used_yadis) {
            $this->assertTrue($s->used_yadis, "Expected to use Yadis");
        } else {
            $this->assertFalse($s->used_yadis,
                               "Expected to use old-style discovery");
        }

        $openid_types = array(
                              '1.1' => Auth_OpenID_TYPE_1_1,
                              '1.0' => Auth_OpenID_TYPE_1_0,
                              '2.0' => Auth_OpenID_TYPE_2_0,
                              '2.0 OP' => Auth_OpenID_TYPE_2_0_IDP);

        $type_uris = array();
        foreach ($types as $t) {
            $type_uris[] = $openid_types[$t];
        }

        $this->assertEquals($type_uris, $s->type_uris);
        $this->assertEquals($canonical_id, $s->canonicalID);
    }

    function setUp()
    {
        $cls = $this->fetcherClass;
        // D is for Dumb.
        $d = array();
        $this->fetcher = new $cls($d);
    }
}

class Tests_Auth_OpenID_Discover_OpenID extends _DiscoveryBase {
    function _discover($content_type, $data,
                       $expected_services, $expected_id=null)
    {
        if ($expected_id === null) {
            $expected_id = $this->id_url;
        }

        $this->fetcher->documents[$this->id_url] = array($content_type, $data);
        list($id_url, $services) = Auth_OpenID_discover($this->id_url,
                                                        $this->fetcher);
        $this->assertEquals($expected_services, count($services));
        $this->assertEquals($expected_id, $id_url);
        return $services;
    }

    function test_404()
    {
        list($url, $services) = Auth_OpenID_discover($this->id_url . '/404',
                                                     $this->fetcher);
        $this->assertTrue($services == array());
    }

    function test_noOpenID()
    {
        $services = $this->_discover('text/plain',
                                     "junk",
                                     0);

        $services = $this->_discover(
                                     'text/html',
                                     Tests_Auth_OpenID_readdata('test_discover_openid_no_delegate.html'),
                                     1);

        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             $this->id_url,
                             null,
                             array('1.1'),
                             false);
    }

    function test_html1()
    {
        $services = $this->_discover('text/html',
                                     Tests_Auth_OpenID_readdata('test_discover_openid.html'),
                                     1);


        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             'http://smoker.myopenid.com/',
                             null,
                             array('1.1'),
                             false);
    }

    function test_html2()
    {
        $services = $this->_discover('text/html',
                                     Tests_Auth_OpenID_readdata('test_discover_openid2.html'),
                                     1);

        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             'http://smoker.myopenid.com/',
                             null,
                             array('2.0'),
                             false);
    }

    function test_html1And2()
    {
        $services = $this->_discover('text/html',
                                     Tests_Auth_OpenID_readdata('test_discover_openid_1_and_2.html'),
                                     2);

        $types = array('2.0', '1.1');

        for ($i = 0; $i < count($types); $i++) {
            $t = $types[$i];
            $s = $services[$i];

            $this->_checkService(
                $s,
                "http://www.myopenid.com/server",
                $this->id_url,
                'http://smoker.myopenid.com/',
                null,
                array($t),
                false);
        }
    }

    function test_yadisEmpty()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_yadis_0entries.xml'),
                                     0);
    }

    function test_htmlEmptyYadis()
    {
        // HTML document has discovery information, but points to an
        // empty Yadis document.

        // The XRDS document pointed to by "openid_and_yadis.html"
        $this->fetcher->documents[$this->id_url . 'xrds'] =
            array('application/xrds+xml',
                  Tests_Auth_OpenID_readdata('test_discover_yadis_0entries.xml'));

        $services = $this->_discover('text/html',
                                     Tests_Auth_OpenID_readdata('test_discover_openid_and_yadis.html'),
                                     1);

        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             'http://smoker.myopenid.com/',
                             null,
                             array('1.1'),
                             false);
    }

    function test_yadis1NoDelegate()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_yadis_no_delegate.xml'),
                                     1);

        $this->_checkService(
                             $services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             $this->id_url,
                             null,
                             array('1.0'),
                             true);
    }

    function test_yadis2NoLocalID()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_openid2_xrds_no_local_id.xml'),
                                     1);

        $this->_checkService(
                             $services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             $this->id_url,
                             null,
                             array('2.0'),
                             true);
    }

    function test_yadis2()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_openid2_xrds.xml'),
                                     1);

        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             $this->id_url,
                             'http://smoker.myopenid.com/',
                             null,
                             array('2.0'),
                             true);
    }

    function test_yadis2OP()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_yadis_idp.xml'),
                                     1);

        $this->_checkService($services[0],
                             "http://www.myopenid.com/server",
                             null,
                             null,
                             null,
                             array('2.0 OP'),
                             true);
    }

    function test_yadis2OPDelegate()
    {
        // The delegate tag isn't meaningful for OP entries.
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_yadis_idp_delegate.xml'),
                                     1);

        $this->_checkService(
                             $services[0],
                             "http://www.myopenid.com/server",
                             null, null, null,
                             array('2.0 OP'),
                             true);
    }

    function test_yadis2BadLocalID()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_yadis_2_bad_local_id.xml'),
                                     0);
    }

    function test_yadis1And2()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_openid_1_and_2_xrds.xml'),
                                     1);

        $this->_checkService(
            $services[0],
            "http://www.myopenid.com/server",
            $this->id_url,
            'http://smoker.myopenid.com/',
            null,
            array('2.0', '1.1'),
            true);
    }

    function test_yadis1And2BadLocalID()
    {
        $services = $this->_discover('application/xrds+xml',
                                     Tests_Auth_OpenID_readdata('test_discover_openid_1_and_2_xrds_bad_delegate.xml'),
                                     0);
    }
}

class _MockFetcherForXRIProxy {

    function _MockFetcherForXRIProxy($documents)
    {
        $this->documents = $documents;
        $this->fetchlog = array();
    }

    function get($url, $headers=null)
    {
        return $this->fetch($url, $headers);
    }

    function post($url, $body)
    {
        return $this->fetch($url, $body);
    }

    function fetch($url, $body=null, $headers=null)
    {
        $this->fetchlog[] = array($url, $body, $headers);

        $u = parse_url($url);
        $proxy_host = $u['host'];
        $xri = $u['path'];
        $query = $u['query'];

        if ((!$headers) && (!$query)) {
            trigger_error('Error in mock XRI fetcher: no headers or query');
        }

        if (Services_Yadis_startswith($xri, '/')) {
            $xri = substr($xri, 1);
        }

        if (array_key_exists($xri, $this->documents)) {
            list($ctype, $body) = $this->documents[$xri];
            $status = 200;
        } else {
            $status = 404;
            $ctype = 'text/plain';
            $body = '';
        }

        return new Services_Yadis_HTTPResponse($url, $status,
                                               array('content-type' => $ctype),
                                               $body);
    }
}

class Tests_Auth_OpenID_DiscoverSession {
    function Tests_Auth_OpenID_DiscoverSession()
    {
        $this->data = array();
    }

    function set($name, $value)
    {
        $this->data[$name] = $value;
    }

    function get($name, $default=null)
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        } else {
            return $default;
        }
    }

    function del($name)
    {
        unset($this->data[$name]);
    }
}

global $__Tests_BOGUS_SERVICE;
$__Tests_BOGUS_SERVICE = new Auth_OpenID_ServiceEndpoint();
$__Tests_BOGUS_SERVICE->claimed_id = "=really.bogus.endpoint";

function __serviceCheck_discover_cb($url, $fetcher)
{
    global $__Tests_BOGUS_SERVICE;
    return array($url, array($__Tests_BOGUS_SERVICE));
}


?>