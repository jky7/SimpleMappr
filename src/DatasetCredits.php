<?php
namespace SimpleMappr;

final class DatasetCredits
{
    public const DETAILED_RELIEF = [
        'name'=>'ASTER GDEM shaded relief',
        'credit'=>'NASA/METI; NASA EOSDIS GIBS',
        'source'=>'https://www.earthdata.nasa.gov/data/catalog/lpcloud-astgtm-003',
        'citation'=>'https://doi.org/10.5067/ASTER/ASTGTM.003',
        'license'=>'Publicly available NASA data',
        'licenseUrl'=>'https://www.earthdata.nasa.gov/engage/open-data-services-and-software/policies/data-use-policy',
        'changes'=>'GIBS greyscale shaded relief tiles are mosaicked, resampled and reprojected for close map views. Source elevations are approximately 30 metres; imagery is at most approximately 38 metres per pixel at the equator. Coverage and terrain artifacts follow ASTER GDEM.',
    ];
    public const DATASETS = [
        'ecoregions'=>[
            'layers'=>['ecoregions','ecoregionLabels'],
            'name'=>'RESOLVE Ecoregions 2017',
            'credit'=>'Dinerstein et al. (2017)',
            'source'=>'https://ecoregions.world/',
            'citation'=>'https://doi.org/10.1093/biosci/bix014',
            'license'=>'CC BY 4.0',
            'licenseUrl'=>'https://creativecommons.org/licenses/by/4.0/',
            'changes'=>'Source geometry retained; maps may be styled, labeled and reprojected.',
        ],
        'hotspots'=>[
            'layers'=>['conservation','hotspotLabels'],
            'name'=>'Biodiversity Hotspots 2016.1',
            'credit'=>'Hoffman, Koenig, Bunting, Costanza and Williams (2016)',
            'source'=>'https://zenodo.org/records/3261807',
            'citation'=>'https://doi.org/10.5281/zenodo.3261807',
            'copyright'=>'© 2012 Conservation International Foundation',
            'license'=>'CC BY-SA 4.0',
            'licenseUrl'=>'https://creativecommons.org/licenses/by-sa/4.0/',
            'changes'=>'Filtered to 36 hotspot area records; 17 outer-limit records omitted. Source geometry retained; maps may be styled, labeled and reprojected.',
        ],
    ];

    public static function selected(array $layers): array
    {
        return array_filter(self::DATASETS,fn(array $dataset): bool=>(bool)array_intersect($layers,$dataset['layers']));
    }

    public static function url(): string { return SiteUrl::base().'/data-credits'; }

    public static function notice(array $dataset): string
    {
        return $dataset['name'].'. '.$dataset['credit'].'. '.$dataset['source'].' '.$dataset['license'].' '.$dataset['licenseUrl'].' '.$dataset['changes'].(isset($dataset['copyright'])?' '.$dataset['copyright'].'.':'');
    }

    public static function lines(array $datasets,int $width): array
    {
        $text=[];
        foreach ($datasets as $id=>$dataset) {
            $author=$id==='hotspots'?'Hoffman et al. (2016)':$dataset['credit'];
            $text[]=$dataset['name'].'; '.$author.'; '.$dataset['license'];
        }
        if (array_diff(array_keys($datasets),['detailedRelief'])) { $text[]='Styled/reprojected'.(isset($datasets['hotspots'])?'; hotspots filtered':'').'.'; }
        $text[]='Sources, licenses and changes: '.self::url();
        return explode("\n",wordwrap(implode("\n",$text),max(1,(int)floor(($width-16)/6)),"\n",true));
    }

    public static function headers(array $datasets): void
    {
        if (!$datasets || PHP_SAPI==='cli') { return; }
        header('Link: <'.self::url().'>; rel="describedby"',false);
        foreach ($datasets as $dataset) { header('Link: <'.$dataset['licenseUrl'].'>; rel="license"; title="'.$dataset['name'].'"',false); }
    }
}
