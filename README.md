jtl-to-har
==========

Simple JMeter (JTL) to HTTP Archive Format (HAR) translator.

This demo converts a JMeter .jtl output file to a corresponding .har object.
For optimal results, configure JMeter to include HTTP headers, cookies and
response bodies in its output.

Usage
-----

```php
$jtl = new JtlToHar($file or $string or $xmlobj);
$har = $jtl->convert();
print_r($har);
```

Upload the converted JSON object to Jan Odvarko's HAR viewer to see the result:

  http://www.softwareishard.com/har/viewer/

For more information and updates, please see:

  http://labs.watchmouse.com/

Written by: Pieter Ennes <pieter@watchmouse.com>
Copyright: (c) 2010 WatchMouse.com
License: EUPL v1.1

To do
-----

- Align with HAR 1.2
- Add support for JMeter's Cache Manager element (check for HTTP 204/304)

Copyright
---------

Copyright (c) 2010 WatchMouse

Licensed under the EUPL, Version 1.1 or – as soon they will be approved
by the European Commission - subsequent versions of the EUPL (the "Licence");
You may not use this work except in compliance with the Licence. You may
obtain a copy of the Licence at:

  http://ec.europa.eu/idabc/eupl

Unless required by applicable law or agreed to in writing, software
distributed under the Licence is distributed on an "AS IS" basis,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the Licence for the specific language governing permissions and
limitations under the Licence.
