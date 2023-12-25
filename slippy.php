<?php
/**
 * @author gcWorld
 * PHP script to use Bing, Google, and Yandex aerial imagery within the OMSI 2 Editor.
 * modified for naver, kakao map (PRASEOD-)
 */
// test coord 55906 25426 16
include '../setenv.php';

$acceptedSource = ['bing', 'google', 'yandex', 'naver', 'kakao'];

// validity check
if (!in_array($_GET['service'], $acceptedSource))
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
            'key' => getenv('BINGMAPS_APIKEY')
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
            $_GET['type'] = 'satellite';
        
        if(!in_array($_GET['format'], $acceptedExt))
            $_GET['format'] = 'png';

        if(!in_array($_GET['hres'], [2,1]))
            $_GET['hres'] = 1;

        $res = strval(256 * $_GET['hres']);
        $scale = [2,1][$_GET['hres'] - 1];

        $query = [
            'center' => implode(',', toLatLong($_GET['x'], $_GET['y'], $_GET['z'])),
            'maptype' => $_GET['type'],
            'zoom' => $_GET['z'],
            'size' => $res.'x'.$res,
            'scale' => $scale,
            'sensor' => false,
            'format' => $_GET['format'],
            'key' => getenv('GCLOUD_APIKEY')
        ];
        
        Header('Content-Type: image/'.$_GET['format']);
        echo file_get_contents('http://maps.googleapis.com/maps/api/staticmap?'.http_build_query($query));
    break;
    case "yandex":
        // aerial not available
        $query = [
            'lang' => 'en_US',
            'll' => implode(',', array_reverse(toLatLong($_GET['x'], $_GET['y'], $_GET['z']))),
            'z' => $_GET['z'],
            'l' => $_GET['type'],
            'size' => '256,256',
            'apikey' => getenv('YANDEXMAP_APIKEY')
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
            $_GET['format']="jpeg";

        if(!isset($_GET['keyid']))
            $_GET['keyid'] = '';

        if(!in_array($_GET['hres'], [1, 2]))
            $_GET['hres'] = 1;

        $query = [
            'w' => 256,
            'h' => 256,
            'center' => implode(',', array_reverse(toLatLong($_GET['x'], $_GET['y'], $_GET['z']))),
            'level' => $_GET['z'] - 1,
            'maptype' => $_GET['type'],
            'format' => $_GET['format'],
            'scale' => $_GET['hres']
        ];

        $endpoint = 'https://naveropenapi.apigw.ntruss.com/map-static/v2/raster?'.http_build_query($query);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'header' => 'X-NCP-APIGW-API-KEY-ID:'.getenv('NCLOUD_KEYID')."\r\n".
                            'X-NCP-APIGW-API-KEY:'.getenv('NCLOUD_APIKEY')
            ]
        ]);

        Header('Content-Type: image/jpeg');
        echo file_get_contents($endpoint, context: $context);
    break;
    case 'kakao':
        // zoom 최대치 14; 확대 레벨 역순
        // 항공사진 어긋남 있어 권장되지 않음
        $acceptedMapTypes = ['ROADMAP', 'SKYVIEW', 'HYBRID'];
        $coord = toLatLong($_GET['x'], $_GET['y'], $_GET['z']);

        if(!in_array($_GET['type'], $acceptedMapTypes))
            $_GET['type']="SKYVIEW";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization:KakaoAK '.getenv('KAKAO_RESTKEY')
            ]
        ]);

        $query = [
            'x' => $coord['lon'],
            'y' => $coord['lat'],
            'input_coord' => 'WGS84',
            'output_coord' => 'WCONGNAMUL'
        ];

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

        if($_GET['type'] == 'HYBRID')
            $query['RDR'] = 'HybridRender';

        $endpoint = 'https://spi.maps.daum.net/map2/map/'.$typ.'imageservice?'.http_build_query($query);

        Header('Content-Type: image/jpeg');
        echo file_get_contents($endpoint, context: $context);
}

/**
 * Slippy Map Tilename to bing quadKey
 */
function toQuad($tileX, $tileY, $levelOfDetail): string {
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
 */
function toLatLong($x, $y, $z): array {
    $n = pow(2, $z);
    $lon_deg = ($x+0.5) / $n * 360.0 - 180.0;
    $lat_deg = rad2deg(atan(sinh(pi() * (1 - 2 * ($y+0.5) / $n))));
    return ['lat' => $lat_deg, 'lon' => $lon_deg];
}