<?php
/**
 * @author gcWorld
 * PHP script to use Bing, Google, and Yandex aerial imagery within the OMSI 2 Editor.
 * modified for naver, kakao map (PRASEOD-)
 */
// test coord 55906 25426 16
$acceptableSource = ['bing', 'google', 'yandex', 'naver', 'kakao'];

// validity check
if (!in_array($_GET['service'], $acceptableSource))
    $provider="bing";
else
    $provider = $_GET['service'];

if (!isset($_GET['apikey']))
    $_GET['apikey'] = "";

switch ($provider){
    case "bing":
        $acceptedMapTypes = ['Aerial', 'AerialWithLabelsOnDemand', 'Birdseye', 'BirdseyeWithLabels', 'BirdseyeV2', 'BirdseyeV2WithLabels', 'CanvasDark', 'CanvasLight', 'CanvasGray', 'OrdnanceSurvey', 'RoadOnDemand', 'Streetside'];
        if(!in_array($_GET['type'], $acceptedMapTypes))
            $_GET['type']="Aerial";

        $query = [
            'mapVersion' => 'v1',
            'output' => 'json',
            'key' => $_GET['apikey']
        ];

        $endpoint = 'http://dev.virtualearth.net/REST/V1/Imagery/Metadata/'.$_GET['type'].'?'.http_build_query($query);
        $resp = file_get_contents($endpoint);

        if($resp) {
            $resourceSets = json_decode($resp, true)['resourceSets'][0]['resources'][0];
            $subdomain = $resourceSets['imageUrlSubdomains'][rand(0, count($resourceSets['imageUrlSubdomains'])-1)];
            $quadkey = toQuad($_GET['x'], $_GET['y'], $_GET['z']);

            header('Content-Type: image/jpeg');
            echo file_get_contents(str_replace(['{subdomain}', '{quadkey}'], [$subdomain, $quadkey], $resourceSets['imageUrl']));
        } else
            echo "Error in fetching API";

    break;
    case "google":
        $acceptedExt = ['png', 'png8', 'png32', 'gif', 'jpg', 'jpg-baseline'];
        $acceptedMapTypes = ['roadmap', 'satellite', 'terrain', 'hybrid'];
        if(!in_array($_GET['type'], $acceptedMapTypes))
            $_GET['type']="satellite";

        if(isset($_GET['hres'])) {
            if($_GET['hres']=="1") {
                $scale="2";
                $res="256x256";
            } elseif($_GET['hres']=="2") {
                $res="512x512";
                $scale="1";
            }
        } else {
            $res="256x256";
            $scale="1";
        }
        
        if(in_array($_GET['format'], $acceptedExt))
            $format=$_GET['format'];
        else
            $format="png";

        $query = [
            'center' => toLatLong($_GET['x'], $_GET['y'], $_GET['z'], 1),
            'maptype' => $_GET['type'],
            'zoom' => $_GET['z'],
            'size' => $res,
            'scale' => $scale,
            'sensor' => false,
            'format' => $format,
            'key' => $_GET['apikey']
        ];
        
        Header('Content-Type: image/'.$format);
        echo file_get_contents('http://maps.googleapis.com/maps/api/staticmap?'.http_build_query($query));
    break;
    case "yandex":
        // aerial not available
        $query = [
            'lang' => 'en_US',
            'll' => toLatLong($_GET['x'], $_GET['y'], $_GET['z']),
            'z' => $_GET['z'],
            'l' => $_GET['type'],
            'size' => '256,256',
            'apikey' => $_GET['apikey']
        ];
        
        header('Content-Type: image/jpeg');
        echo file_get_contents("http://static-maps.yandex.ru/v1?".http_build_query($query));
    break;
    case "naver":
        $acceptedExt = ['jpg', 'jpeg', 'png8', 'png'];
        $acceptedMapTypes = ['basic', 'traffic', 'satellite', 'satellite_base', 'terrain'];
        
        if(!in_array($_GET['type'], $acceptedMapTypes))
            $_GET['type']="satellite_base";

        if(!in_array($_GET['format'], $acceptedExt))
            $_GET['format']="png";

        if(!isset($_GET['keyid']))
            $_GET['keyid'] = '';

        if(in_array($_GET['hres'], [1, 2]))
            $scale = $_GET['hres'];
        else
            $scale = 1;

        $query = [
            'w' => 512,
            'h' => 512,
            'center' => toLatLong($_GET['x'], $_GET['y'], $_GET['z']),
            'level' => $_GET['z'],
            'maptype' => $_GET['type'],
            'format' => $_GET['format'],
            'scale' => $scale
        ];

        $endpoint = 'https://naveropenapi.apigw.ntruss.com/map-static/v2/raster?'.http_build_query($query);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'header' => 'X-NCP-APIGW-API-KEY-ID:'.$_GET['keyid']."\r\n".
                            'X-NCP-APIGW-API-KEY:'.$_GET['apikey']
            ]
        ]);

        Header('Content-Type: image/jpeg');
        echo file_get_contents($endpoint, context: $context);
    break;
    case 'kakao':
        // zoom 최대치 14; 확대 레벨 역순
        // 항공사진 어긋남 있어 권장되지 않음
        $acceptedMapTypes = ['ROADMAP', 'SKYVIEW', 'HYBRID'];
        $coord = explode(',', toLatLong($_GET['x'], $_GET['y'], $_GET['z']));

        if(!in_array($_GET['type'], $acceptedMapTypes))
            $_GET['type']="SKYVIEW";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization:KakaoAK '.$_GET['apikey']
            ]
        ]);

        $query = [
            'x' => $coord[0],
            'y' => $coord[1],
            'input_coord' => 'WGS84',
            'output_coord' => 'WCONGNAMUL'
        ];

        if($_GET['type'] == 'HYBRID')
            $query['RDR'] = 'HybridRender';

        if($_GET['type'] == 'ROADMAP')
            $typ = '';
        else
            $typ = 'skyview';

        // get kakao tile position
        $endpoint = 'https://dapi.kakao.com/v2/local/geo/transcoord.json?'.http_build_query($query);
        $tile = json_decode(file_get_contents($endpoint, context: $context), true)['documents'][0];

        $query = [
            'MX' => $tile['x'],
            'MY' => $tile['y'],
            'IW' => '242',
            'IH' => '242',
            'SCALE' => (0.3125 * pow(2, 20 - $_GET['z'])),
            'service' => 'open'
        ];

        $endpoint = 'https://spi.maps.daum.net/map2/map/skyviewimageservice?'.http_build_query($query);

        Header('Content-Type: image/jpeg');
        echo file_get_contents($endpoint, context: $context);
}

/**
 * Slippy Map Tilename to bing quadKey
 */
function toQuad($tileX, $tileY, $levelOfDetail) {
    $quadKey = '';
    for ($i = $levelOfDetail; $i > 0; $i--) {
        $digit = '0';
        $mask = 1 << ($i - 1);
        if (($tileX & $mask) != 0) {
            $digit++;
        }
        if (($tileY & $mask) != 0) {
            $digit++;
            $digit++;
        }
        $quadKey .= $digit;
    }
    return $quadKey;
}

/**
 * Slippy Map Tilename to WGS84
 * @param string $d delimiter between lon. and lat.
 */
function toLatLong($x, $y, $z, $latlon = 0, $d = ',') {
    $n = pow(2, $z);
    $lon_deg = ($x+0.5) / $n * 360.0 - 180.0;
    $lat_deg = rad2deg(atan(sinh(pi() * (1 - 2 * ($y+0.5) / $n))));
    if($latlon == 0)
        $return_string = $lon_deg.$d.$lat_deg;
    elseif($latlon == 1)
        $return_string = $lat_deg.$d.$lon_deg;
    return $return_string;
}
