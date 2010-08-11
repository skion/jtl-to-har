<?php
/**
 * Simple JTL to HAR translator (PoC)
 *
 * This demo converts a JMeter .jtl output file to a corresponding .har object.
 * For optimal results, configure JMeter to include HTTP headers, cookies and
 * response bodies in its output.
 *
 * Usage:
 *   $jtl = new JtlToHar($file or $string or $xmlobj);
 *   $har = $jtl->convert();
 *   print_r($har);
 *
 * Upload the converted JSON object to Jan Odvarko's HAR viewer to see the result:
 *
 *   http://www.softwareishard.com/har/viewer/
 *
 * For more information and updates, please see:
 *
 *   http://labs.watchmouse.com/
 *
 * @author Pieter Ennes <pieter@watchmouse.com>
 * @copyright (c) 2010 WatchMouse.com
 * @version $Id$
 * @license EUPL v1.1
 *
 * @todo
 *   - Align with HAR 1.2
 *   - Add support for JMeter's Cache Manager element (check for HTTP 204/304)
 *
 * Copyright (c) 2010 WatchMouse
 *
 * Licensed under the EUPL, Version 1.1 or – as soon they will be approved
 * by the European Commission - subsequent versions of the EUPL (the "Licence");
 * You may not use this work except in compliance with the Licence. You may
 * obtain a copy of the Licence at:
 *
 *   http://ec.europa.eu/idabc/eupl
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the Licence for the specific language governing permissions and
 * limitations under the Licence.
 */


class JtlToHar
{
    // the HAR specification we do
    const   har_version     = '1.1';

    // that's us and version
    const   creator_name    = 'WatchMouse';
    const   creator_version = '0.1';

    // xml loading errors
    public $errors;

    // jtl xml object
    private $xml = false;
    private $pages = false;
    private $entries = false;

    public function __construct($src)
    {
        // disable PHP's XML error handling
        libxml_use_internal_errors(true);

        if($src instanceof SimpleXMLElement)
        {
            $this->xml = $src;
        }
        elseif(file_exists($src))
        {
            $this->xml = simplexml_load_file($src);
        }
        elseif(is_string($src))
        {
            $this->xml = simplexml_load_string($src);
        }

        $this->errors = libxml_get_errors();

        if(!$this->xml)
        {
            $this->dump_errors();
            throw new Exception("Failed to load JTL source");
        }
    }

    public function __destruct()
    {
        // avoid possible memory loss
        libxml_clear_errors();
    }

    public function convert()
    {
        $json  = array
        (
            'log' => array
            (
               'version' => $this->output_version(),
               'creator' => $this->output_creator(),
               'browser' => $this->output_browser(),
               'pages'   => $this->output_pages(),
               'entries' => $this->output_entries(),
            )
        );
        return json_encode($json);
    }

    private function output_version()
    {
        return self::har_version;
    }

    private function output_creator()
    {
        return array
        (
            'name' => self::creator_name,
            'version' => self::creator_version,
        );
    }

    private function output_browser()
    {
        $elem = (string) $this->xml['version'];
        if(!$elem) $elem = 'N/A';

        return array
        (
            'name' => 'Jakarta JMeter',
            'version' => $elem,
        );
    }

    private function output_pages()
    {
        if(!$this->pages)
        {
            $this->make_pages_and_entries();
        }
        return $this->pages;
    }

    private function output_entries()
    {
        if(!$this->entries)
        {
            $this->make_pages_and_entries();
        }
        return $this->entries;
    }

    // we make pages and entries in one go...
    private function make_pages_and_entries()
    {
        $id = 0;
        $this->pages = array();
        $this->entries = array();

        // loop over all main samples
        // TODO: this needs logic to cope with the different meanings of child elements in jmeter
        foreach($this->xml->xpath('/testResults/httpSample') as $sample)
        {
        	$child = $sample->httpSample[0];

        	// looks like a http redirect?
        	if($child && ((int) $child['rc'] == 301 || (int) $child['rc'] == 302))
            {
            	// then the parent is actually a virtual sample
                $this->pages[] = $this->output_page($sample, $id);

                // so we skip the parent entry
//              $this->entries[] = $this->output_entry($sample, $id);

                // and for all child elements
                foreach($sample->httpSample as $child)
                {
                    $this->entries[] = $this->output_entry($child, $id);
                }
            }
            else
            {
            	// here the parent is the main
            	// assume children are embedded elements
                $this->pages[] = $this->output_page($sample, $id);
                // create an entry for the parent request
                $this->entries[] = $this->output_entry($sample, $id);

                // and for all child elements
                foreach($sample->httpSample as $child)
                {
                    $this->entries[] = $this->output_entry($child, $id);
                }
            }
            $id += 1;
        }
    }

    private function output_page($sample, $id)
    {
        return array
        (
            'id'                => "page_$id",
            'title'             => (string) $sample['lb'],
            'startedDateTime'   => $this->convert_timestamp($sample['ts']),
            'pageTimings'       => array
            (
               'onLoad'         => (int) $sample['lt'],
               'onContentLoad'  => (int) $sample['t'],  // TODO What to time here, should represent full page load
            )
        );
    }

    private function output_entry($sample, $id)
    {
    	$timings = $this->output_entry_timings($sample);

    	return array
        (
            'pageref'           => "page_$id",
            'startedDateTime'   => $this->convert_timestamp($sample['ts']),
            'request'           => $this->output_entry_request($sample),
            'response'          => $this->output_entry_response($sample),
            'cache'             => $this->output_entry_cache($sample),
            'timings'           => $timings,
            // total time, sum of individual timings
            'time'              => ($timings['dns'] > 0 ? $timings['dns'] : 0) + ($timings['connect'] > 0 ? $timings['connect'] : 0) + $timings['send'] + $timings['wait'] + $timings['receive'],
            '_assertions'       => $this->output_entry_assertions($sample),
        );
    }

    private function output_entry_timings($sample)
    {
        return array
        (
            'blocked'   => -1,
            'dns'       => -1,
            'connect'   => isset($sample['ct']) ? (int) $sample['ct'] : -1,
            'send'      => -1,
            'wait'      => (int) $sample['lt'] - (isset($sample['ct']) ? (int) $sample['ct'] : -1),
            'receive'   => (int) $sample['t'] - (int) $sample['lt'],
        );
    }

    private function output_entry_request($sample)
    {
    	$headers = $this->convert_headers($sample->requestHeader);

    	return array
        (
            'method'        => isset($sample->method) ? (string) $sample->method : "N/A",
            'url'           => isset($sample->{'java.net.URL'}) ? (string) $sample->{'java.net.URL'} : "N/A",
            'httpVersion'   => isset($sample->responseHeader) ? substr((string) $sample->responseHeader, 0, 8) : "N/A",
            'cookies'       => array(),
            'headers'       => $this->output_entry_headers($headers),
            'queryString'   => array(), //@@@ decompose (string) $sample->queryString,
    //        'postData'      => -1,
            'headersSize'   => isset($sample->requestHeader) ? strlen((string) $sample->requestHeader) : -1,
            'bodySize'      => -1,
        );
    }

    private function output_entry_response($sample)
    {
        $headers = $this->convert_headers($sample->responseHeader);

    	return array
        (
            'status'        => (int) $sample['rc'],
            'statusText'    => (string) $sample['rm'],
            'httpVersion'   => substr((string) $sample->responseHeader, 0, 8),
            'cookies'       => $this->output_entry_cookies($sample),
            'headers'       => $this->output_entry_headers($headers),
            'content'       => $this->output_entry_content($sample, $headers),
            'redirectURL'   => (string) $sample->redirectLocation,
            'headersSize'   => isset($sample->responseHeader) ? strlen((string) $sample->responseHeader) : -1,
            'bodySize'      => (int) $sample['by'],
        );
    }

    private function output_entry_content($sample, $headers)
    {
        $type    = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        $mime    = $type;
        $charset = 'utf-8';

        // get content mime-type
        //if(preg_match('/^([^;]+)/', $type, $m))
        //{
        //    $mime = $m[1];
        //}

        // get content charset
        if(preg_match('/charset="?(.*)"?/', $type, $m))
        {
            $charset = $m[1];
        }

        return array
        (
            'size'          => isset($headers['Content-Length']) ? $headers['Content-Length'] : strlen($sample->responseData),
            'compression'   => isset($headers['Content-Encoding']) ? $headers['Content-Encoding'] == 'gzip' || $headers['Content-Encoding'] == 'deflate' : 0, # @FIXME
            'mimeType'      => $mime, // @FIXME should support complete Content-Type string
            // convert to utf-8 as per spec
            'text'          => isset($sample->responseData) ? mb_convert_encoding($sample->responseData, 'utf-8', $charset) : 'Content not available',
        );
    }

    private function output_entry_cache($sample)
    {
        return array();
    }

    private function output_entry_assertions($sample)
    {
        $assertions = array();
        foreach($sample->assertionResult as $elem)
        {
            $assertions[] = array
            (
                'name'      => (string) $elem->name,
                'failure'   => (string) $elem->failure == 'true' ? 1 : 0,
                'error'     => (string) $elem->error == 'true' ? 1 : 0,
                'message'   => (string) $elem->failureMessage,
            );
        }
        return $assertions;
    }

    private function output_entry_cookies($sample)
    {
        $output = array();
        if(isset($sample->cookies))
        {
            // PREF=ID=950ecd217f3958e0:TM=1273686556:LM=1273686556:S=1n3uQ44BPheYfSEy; $Path=/; $Domain=.google.com;
            // NID=34=MHPECCYXrJv_MnDwEKpcEVNnUpliqg9snt64-0m1YSM7Wonu5ng9eadKV2Br1CCujcbMtgTIkp5tfO2xN8LtBELoklswdN_jKj5SHpMLgdIWvY3NGVtnTujpqRPCE9WC; $Path=/; $Domain=.google.com
            $num = preg_match_all('/\s*([^\$]\w*)=([^;]+);\s*\$Path=([^;]*);\s*\$Domain=([^;]+);?\s*/', $sample->cookies, $ms, PREG_SET_ORDER);
            if($num)
            {
                foreach($ms as $m)
	        {
	            $output[] = array('name' => $m[1], 'value' => $m[2], 'path' => $m[3], 'domain' => $m[4]);
	        }
            }
        }
        return $output;
    }

    /**
     * Convert header array (key/value) into json object.
     *
     * @param array $headers
     * @return array
     */
    private function output_entry_headers($headers)
    {
        $output = array();
        foreach($headers as $key => $val)
        {
             // to include in the HAR
             $output[] = array('name' => $key, 'value' => $val);
        }
        return $output;
    }


    /**
     * Split header string into array.
     *
     * @param string $str
     * @return array
     */
    private function convert_headers($str)
    {
        $headers = array();
        if($str)
        {
            $lines = explode("\n", $str);
            foreach($lines as $line)
            {
                // looks like a header?
                if(preg_match('/^\s*([^:]+):\s*(.*)\s*$/', $line, $m))
                {
                    // to use further on
                    $headers[$m[1]] = $m[2];
                }
            }
        }
        return $headers;
    }

    // convert timestamp with milli's to ISO 8601 string
    private function convert_timestamp($ts)
    {
        // date in ISO 8601
        $s = date('c', bcdiv($ts, 1000));
        // inject milliseconds part
        return preg_replace('/(\+)/', '.' . bcmod($ts, 1000) . '+', $s);
    }

    public function dump_errors()
    {
        foreach($this->errors as $error)
        {
            echo $this->display_error($error);
        }
    }

    // stolen from: http://uk2.php.net/manual/en/simplexml.examples-errors.php
    private function display_error($error)
    {
        switch ($error->level)
        {
            case LIBXML_ERR_WARNING:
                $return = "Warning $error->code: ";
                break;
             case LIBXML_ERR_ERROR:
                $return = "Error $error->code: ";
                break;
            case LIBXML_ERR_FATAL:
                $return = "Fatal Error $error->code: ";
                break;
        }

        $return .= trim($error->message) .
                   "\n  Line: $error->line" .
                   "\n  Column: $error->column";

        if ($error->file)
        {
            $return .= "\n  File: $error->file";
        }

        return "$return\n\n--------------------------------------------\n\n";
    }

}
?>