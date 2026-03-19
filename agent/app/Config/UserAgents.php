<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * User Agents configuration.
 * Required by the HTTP layer for web request handling.
 */
class UserAgents extends BaseConfig
{
    /**
     * @var array<string, string>
     */
    public array $platforms = [
        'windows nt 10.0' => 'Windows 10',
        'windows nt 6.3'  => 'Windows 8.1',
        'windows nt 6.2'  => 'Windows 8',
        'windows nt 6.1'  => 'Windows 7',
        'windows nt 6.0'  => 'Windows Vista',
        'windows nt 5.2'  => 'Windows 2003',
        'windows nt 5.1'  => 'Windows XP',
        'windows nt 5.0'  => 'Windows 2000',
        'windows nt 4.0'  => 'Windows NT 4.0',
        'winnt4.0'        => 'Windows NT 4.0',
        'winnt 4.0'       => 'Windows NT',
        'winnt'           => 'Windows NT',
        'windows 98'      => 'Windows 98',
        'win98'           => 'Windows 98',
        'windows 95'      => 'Windows 95',
        'win95'           => 'Windows 95',
        'windows phone'   => 'Windows Phone',
        'windows'         => 'Unknown Windows OS',
        'android'         => 'Android',
        'blackberry'      => 'BlackBerry',
        'iphone'          => 'iOS',
        'ipad'            => 'iOS',
        'ipod'            => 'iOS',
        'os x'            => 'Mac OS X',
        'ppc mac'         => 'Power PC Mac',
        'freebsd'         => 'FreeBSD',
        'ppc'             => 'Macintosh',
        'linux'           => 'Linux',
        'debian'          => 'Debian',
        'sunos'           => 'Sun Solaris',
        'beos'            => 'BeOS',
        'apachebench'     => 'ApacheBench',
        'aix'             => 'AIX',
        'irix'            => 'Irix',
        'osf'             => 'DEC OSF',
        'hp-ux'           => 'HP-UX',
        'netbsd'          => 'NetBSD',
        'bsdi'            => 'BSDi',
        'openbsd'         => 'OpenBSD',
        'gnu'             => 'GNU/Linux',
        'unix'            => 'Unknown Unix OS',
        'symbian'         => 'SymbianOS',
    ];

    /**
     * @var array<string, string>
     */
    public array $browsers = [
        'OPR'    => 'Opera',
        'Flock'  => 'Flock',
        'Edge'   => 'Spartan',
        'Edg'    => 'Edge',
        'Chrome' => 'Chrome',
        'Opera.*?Version'  => 'Opera',
        'Opera'            => 'Opera',
        'MSIE'             => 'Internet Explorer',
        'Internet Explorer' => 'Internet Explorer',
        'Trident.* rv'     => 'Internet Explorer',
        'Shiira'           => 'Shiira',
        'Firefox'          => 'Firefox',
        'Chimera'          => 'Chimera',
        'Phoenix'          => 'Phoenix',
        'Firebird'         => 'Firebird',
        'Camino'           => 'Camino',
        'Netscape'         => 'Netscape',
        'OmniWeb'          => 'OmniWeb',
        'Safari'           => 'Safari',
        'Mozilla'          => 'Mozilla',
        'Konqueror'        => 'Konqueror',
        'icab'             => 'iCab',
        'Lynx'             => 'Lynx',
        'Links'            => 'Links',
        'hotjava'          => 'HotJava',
        'amaya'            => 'Amaya',
        'IBrowse'          => 'IBrowse',
        'Maxthon'          => 'Maxthon',
        'Ubuntu'           => 'Ubuntu Web Browser',
        'Vivaldi'          => 'Vivaldi',
    ];

    /**
     * @var array<string, string>
     */
    public array $mobiles = [
        'mobileexplorer' => 'Mobile Explorer',
        'palmsource'     => 'Palm',
        'palmscape'      => 'Palmscape',
        'motorola'       => 'Motorola',
        'nokia'          => 'Nokia',
        'palm'           => 'Palm',
        'iphone'         => 'Apple iPhone',
        'ipad'           => 'iPad',
        'ipod'           => 'Apple iPod Touch',
        'sony'           => 'Sony Ericsson',
        'ericsson'       => 'Sony Ericsson',
        'blackberry'     => 'BlackBerry',
        'cocoon'         => 'O2 Cocoon',
        'blazer'         => 'Treo',
        'lg'             => 'LG',
        'amoi'           => 'Amoi',
        'xda'            => 'XDA',
        'mda'            => 'MDA',
        'vario'          => 'Vario',
        'htc'            => 'HTC',
        'samsung'        => 'Samsung',
        'sharp'          => 'Sharp',
        'sie-'           => 'Siemens',
        'alcatel'        => 'Alcatel',
        'benq'           => 'BenQ',
        'ipaq'           => 'HP iPaq',
        'mot-'           => 'Motorola',
        'playstation portable' => 'PlayStation Portable',
        'pixel'          => 'Google Pixel',
        'android'        => 'Android',
    ];

    /**
     * @var array<string, string>
     */
    public array $robots = [
        'googlebot'       => 'Googlebot',
        'msnbot'          => 'MSNBot',
        'baiduspider'     => 'Baiduspider',
        'bingbot'         => 'Bing',
        'slurp'           => 'Inktomi Slurp',
        'yahoo'           => 'Yahoo',
        'ask jeeves'      => 'Ask Jeeves',
        'fastcrawler'     => 'FastCrawler',
        'infoseek'        => 'InfoSeek Robot 1.0',
        'lycos'           => 'Lycos',
        'yandex'          => 'YandexBot',
        'mediapartners-google' => 'MediaPartners Google',
        'CRAZYWEBCRAWLER' => 'Crazy Webcrawler',
        'adsbot-google'   => 'AdsBot Google',
        'feedfetcher-google' => 'Feedfetcher Google',
        'curious george'  => 'Curious George',
        'ia_archiver'     => 'Alexa Crawler',
        'MJ12bot'         => 'Majestic-12',
        'Uptimebot'       => 'Uptimebot',
        'curl'            => 'curl',
    ];
}
