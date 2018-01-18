<?php

namespace Nominatim;

require_once(CONST_BasePath.'/lib/PlaceLookup.php');
require_once(CONST_BasePath.'/lib/Phrase.php');
require_once(CONST_BasePath.'/lib/ReverseGeocode.php');
require_once(CONST_BasePath.'/lib/SearchDescription.php');
require_once(CONST_BasePath.'/lib/SearchContext.php');

class Geocode
{
    protected $oDB;

    protected $oPlaceLookup;

    protected $aLangPrefOrder = array();

    protected $bIncludeAddressDetails = false;

    protected $aExcludePlaceIDs = array();
    protected $bReverseInPlan = false;

    protected $iLimit = 20;
    protected $iFinalLimit = 10;
    protected $iOffset = 0;
    protected $bFallback = false;

    protected $aCountryCodes = false;

    protected $bBoundedSearch = false;
    protected $aViewBox = false;
    protected $aRoutePoints = false;
    protected $aRouteWidth = false;

    protected $iMaxRank = 20;
    protected $iMinAddressRank = 0;
    protected $iMaxAddressRank = 30;
    protected $aAddressRankList = array();

    protected $sAllowedTypesSQLList = false;

    protected $sQuery = false;
    protected $aStructuredQuery = false;

    protected $oNormalizer = null;


    public function __construct(&$oDB)
    {
        $this->oDB =& $oDB;
        $this->oPlaceLookup = new PlaceLookup($this->oDB);
        $this->oNormalizer = \Transliterator::createFromRules(CONST_Term_Normalization_Rules);
    }

    private function normTerm($sTerm)
    {
        if ($this->oNormalizer === null) {
            return $sTerm;
        }

        return $this->oNormalizer->transliterate($sTerm);
    }

    public function setReverseInPlan($bReverse)
    {
        $this->bReverseInPlan = $bReverse;
    }

    public function setLanguagePreference($aLangPref)
    {
        $this->aLangPrefOrder = $aLangPref;
    }

    public function getMoreUrlParams()
    {
        if ($this->aStructuredQuery) {
            $aParams = $this->aStructuredQuery;
        } else {
            $aParams = array('q' => $this->sQuery);
        }

        $aParams = array_merge($aParams, $this->oPlaceLookup->getMoreUrlParams());

        if ($this->aExcludePlaceIDs) {
            $aParams['exclude_place_ids'] = implode(',', $this->aExcludePlaceIDs);
        }

        if ($this->bIncludeAddressDetails) $aParams['addressdetails'] = '1';
        if ($this->bBoundedSearch) $aParams['bounded'] = '1';

        if ($this->aCountryCodes) {
            $aParams['countrycodes'] = implode(',', $this->aCountryCodes);
        }

        if ($this->aViewBox) {
            $aParams['viewbox'] = join(',', $this->aViewBox);
        }

        return $aParams;
    }

    public function setLimit($iLimit = 10)
    {
        if ($iLimit > 50) $iLimit = 50;
        if ($iLimit < 1) $iLimit = 1;

        $this->iFinalLimit = $iLimit;
        $this->iLimit = $iLimit + min($iLimit, 10);
    }

    public function setFeatureType($sFeatureType)
    {
        switch ($sFeatureType) {
            case 'country':
                $this->setRankRange(4, 4);
                break;
            case 'state':
                $this->setRankRange(8, 8);
                break;
            case 'city':
                $this->setRankRange(14, 16);
                break;
            case 'settlement':
                $this->setRankRange(8, 20);
                break;
        }
    }

    public function setRankRange($iMin, $iMax)
    {
        $this->iMinAddressRank = $iMin;
        $this->iMaxAddressRank = $iMax;
    }

    public function setViewbox($aViewbox)
    {
        $aBox = array_map('floatval', $aViewbox);

        $this->aViewBox[0] = max(-180.0, min($aBox[0], $aBox[2]));
        $this->aViewBox[1] = max(-90.0, min($aBox[1], $aBox[3]));
        $this->aViewBox[2] = min(180.0, max($aBox[0], $aBox[2]));
        $this->aViewBox[3] = min(90.0, max($aBox[1], $aBox[3]));

        if ($this->aViewBox[2] - $this->aViewBox[0] < 0.000000001
            || $this->aViewBox[3] - $this->aViewBox[1] < 0.000000001
        ) {
            userError("Bad parameter 'viewbox'. Not a box.");
        }
    }

    private function viewboxImportanceFactor($fX, $fY)
    {
        $fWidth = ($this->aViewBox[2] - $this->aViewBox[0])/2;
        $fHeight = ($this->aViewBox[3] - $this->aViewBox[1])/2;

        $fXDist = abs($fX - ($this->aViewBox[0] + $this->aViewBox[2])/2);
        $fYDist = abs($fY - ($this->aViewBox[1] + $this->aViewBox[3])/2);

        if ($fXDist <= $fWidth && $fYDist <= $fHeight) {
            return 1;
        }

        if ($fXDist <= $fWidth * 3 && $fYDist <= 3 * $fHeight) {
            return 0.5;
        }

        return 0.25;
    }

    public function setQuery($sQueryString)
    {
        $this->sQuery = $sQueryString;
        $this->aStructuredQuery = false;
    }

    public function getQueryString()
    {
        return $this->sQuery;
    }


    public function loadParamArray($oParams, $sForceGeometryType = null)
    {
        $this->bIncludeAddressDetails
         = $oParams->getBool('addressdetails', $this->bIncludeAddressDetails);

        $this->bBoundedSearch = $oParams->getBool('bounded', $this->bBoundedSearch);

        $this->setLimit($oParams->getInt('limit', $this->iFinalLimit));
        $this->iOffset = $oParams->getInt('offset', $this->iOffset);

        $this->bFallback = $oParams->getBool('fallback', $this->bFallback);

        // List of excluded Place IDs - used for more acurate pageing
        $sExcluded = $oParams->getStringList('exclude_place_ids');
        if ($sExcluded) {
            foreach ($sExcluded as $iExcludedPlaceID) {
                $iExcludedPlaceID = (int)$iExcludedPlaceID;
                if ($iExcludedPlaceID)
                    $aExcludePlaceIDs[$iExcludedPlaceID] = $iExcludedPlaceID;
            }

            if (isset($aExcludePlaceIDs))
                $this->aExcludePlaceIDs = $aExcludePlaceIDs;
        }

        // Only certain ranks of feature
        $sFeatureType = $oParams->getString('featureType');
        if (!$sFeatureType) $sFeatureType = $oParams->getString('featuretype');
        if ($sFeatureType) $this->setFeatureType($sFeatureType);

        // Country code list
        $sCountries = $oParams->getStringList('countrycodes');
        if ($sCountries) {
            foreach ($sCountries as $sCountryCode) {
                if (preg_match('/^[a-zA-Z][a-zA-Z]$/', $sCountryCode)) {
                    $aCountries[] = strtolower($sCountryCode);
                }
            }
            if (isset($aCountries))
                $this->aCountryCodes = $aCountries;
        }

        $aViewbox = $oParams->getStringList('viewboxlbrt');
        if ($aViewbox) {
            if (count($aViewbox) != 4) {
                userError("Bad parmater 'viewboxlbrt'. Expected 4 coordinates.");
            }
            $this->setViewbox($aViewbox);
        } else {
            $aViewbox = $oParams->getStringList('viewbox');
            if ($aViewbox) {
                if (count($aViewbox) != 4) {
                    userError("Bad parmater 'viewbox'. Expected 4 coordinates.");
                }
                $this->setViewBox($aViewbox);
            } else {
                $aRoute = $oParams->getStringList('route');
                $fRouteWidth = $oParams->getFloat('routewidth');
                if ($aRoute && $fRouteWidth) {
                    $this->aRoutePoints = $aRoute;
                    $this->aRouteWidth = $fRouteWidth;
                }
            }
        }

        $this->oPlaceLookup->loadParamArray($oParams, $sForceGeometryType);
        $this->oPlaceLookup->setIncludeAddressDetails(false);
        $this->oPlaceLookup->setIncludePolygonAsPoints($oParams->getBool('polygon'));
    }

    public function setQueryFromParams($oParams)
    {
        // Search query
        $sQuery = $oParams->getString('q');
        if (!$sQuery) {
            $this->setStructuredQuery(
                $oParams->getString('amenity'),
                $oParams->getString('street'),
                $oParams->getString('city'),
                $oParams->getString('county'),
                $oParams->getString('state'),
                $oParams->getString('country'),
                $oParams->getString('postalcode')
            );
            $this->setReverseInPlan(false);
        } else {
            $this->setQuery($sQuery);
        }
    }

    public function loadStructuredAddressElement($sValue, $sKey, $iNewMinAddressRank, $iNewMaxAddressRank, $aItemListValues)
    {
        $sValue = trim($sValue);
        if (!$sValue) return false;
        $this->aStructuredQuery[$sKey] = $sValue;
        if ($this->iMinAddressRank == 0 && $this->iMaxAddressRank == 30) {
            $this->iMinAddressRank = $iNewMinAddressRank;
            $this->iMaxAddressRank = $iNewMaxAddressRank;
        }
        if ($aItemListValues) $this->aAddressRankList = array_merge($this->aAddressRankList, $aItemListValues);
        return true;
    }

    public function setStructuredQuery($sAmenity = false, $sStreet = false, $sCity = false, $sCounty = false, $sState = false, $sCountry = false, $sPostalCode = false)
    {
        $this->sQuery = false;

        // Reset
        $this->iMinAddressRank = 0;
        $this->iMaxAddressRank = 30;
        $this->aAddressRankList = array();

        $this->aStructuredQuery = array();
        $this->sAllowedTypesSQLList = false;

        $this->loadStructuredAddressElement($sAmenity, 'amenity', 26, 30, false);
        $this->loadStructuredAddressElement($sStreet, 'street', 26, 30, false);
        $this->loadStructuredAddressElement($sCity, 'city', 14, 24, false);
        $this->loadStructuredAddressElement($sCounty, 'county', 9, 13, false);
        $this->loadStructuredAddressElement($sState, 'state', 8, 8, false);
        $this->loadStructuredAddressElement($sPostalCode, 'postalcode', 5, 11, array(5, 11));
        $this->loadStructuredAddressElement($sCountry, 'country', 4, 4, false);

        if (sizeof($this->aStructuredQuery) > 0) {
            $this->sQuery = join(', ', $this->aStructuredQuery);
            if ($this->iMaxAddressRank < 30) {
                $this->sAllowedTypesSQLList = '(\'place\',\'boundary\')';
            }
        }
    }

    public function fallbackStructuredQuery()
    {
        if (!$this->aStructuredQuery) return false;

        $aParams = $this->aStructuredQuery;

        if (sizeof($aParams) == 1) return false;

        $aOrderToFallback = array('postalcode', 'street', 'city', 'county', 'state');

        foreach ($aOrderToFallback as $sType) {
            if (isset($aParams[$sType])) {
                unset($aParams[$sType]);
                $this->setStructuredQuery(@$aParams['amenity'], @$aParams['street'], @$aParams['city'], @$aParams['county'], @$aParams['state'], @$aParams['country'], @$aParams['postalcode']);
                return true;
            }
        }

        return false;
    }

    public function getGroupedSearches($aSearches, $aPhrases, $aValidTokens, $bIsStructured)
    {
        /*
             Calculate all searches using aValidTokens i.e.
             'Wodsworth Road, Sheffield' =>

             Phrase Wordset
             0      0       (wodsworth road)
             0      1       (wodsworth)(road)
             1      0       (sheffield)

             Score how good the search is so they can be ordered
         */
        foreach ($aPhrases as $iPhrase => $oPhrase) {
            $aNewPhraseSearches = array();
            $sPhraseType = $bIsStructured ? $oPhrase->getPhraseType() : '';

            foreach ($oPhrase->getWordSets() as $iWordSet => $aWordset) {
                // Too many permutations - too expensive
                if ($iWordSet > 120) break;

                $aWordsetSearches = $aSearches;

                // Add all words from this wordset
                foreach ($aWordset as $iToken => $sToken) {
                    //echo "<br><b>$sToken</b>";
                    $aNewWordsetSearches = array();

                    foreach ($aWordsetSearches as $oCurrentSearch) {
                        //echo "<i>";
                        //var_dump($oCurrentSearch);
                        //echo "</i>";

                        // If the token is valid
                        if (isset($aValidTokens[' '.$sToken])) {
                            foreach ($aValidTokens[' '.$sToken] as $aSearchTerm) {
                                $aNewSearches = $oCurrentSearch->extendWithFullTerm(
                                    $aSearchTerm,
                                    isset($aValidTokens[$sToken])
                                      && strpos($sToken, ' ') === false,
                                    $sPhraseType,
                                    $iToken == 0 && $iPhrase == 0,
                                    $iPhrase == 0,
                                    $iToken + 1 == sizeof($aWordset)
                                      && $iPhrase + 1 == sizeof($aPhrases)
                                );

                                foreach ($aNewSearches as $oSearch) {
                                    if ($oSearch->getRank() < $this->iMaxRank) {
                                        $aNewWordsetSearches[] = $oSearch;
                                    }
                                }
                            }
                        }
                        // Look for partial matches.
                        // Note that there is no point in adding country terms here
                        // because country is omitted in the address.
                        if (isset($aValidTokens[$sToken]) && $sPhraseType != 'country') {
                            // Allow searching for a word - but at extra cost
                            foreach ($aValidTokens[$sToken] as $aSearchTerm) {
                                $aNewSearches = $oCurrentSearch->extendWithPartialTerm(
                                    $aSearchTerm,
                                    $bIsStructured,
                                    $iPhrase,
                                    isset($aValidTokens[' '.$sToken]) ? $aValidTokens[' '.$sToken] : array()
                                );

                                foreach ($aNewSearches as $oSearch) {
                                    if ($oSearch->getRank() < $this->iMaxRank) {
                                        $aNewWordsetSearches[] = $oSearch;
                                    }
                                }
                            }
                        }
                    }
                    // Sort and cut
                    usort($aNewWordsetSearches, array('Nominatim\SearchDescription', 'bySearchRank'));
                    $aWordsetSearches = array_slice($aNewWordsetSearches, 0, 50);
                }
                //var_Dump('<hr>',sizeof($aWordsetSearches)); exit;

                $aNewPhraseSearches = array_merge($aNewPhraseSearches, $aNewWordsetSearches);
                usort($aNewPhraseSearches, array('Nominatim\SearchDescription', 'bySearchRank'));

                $aSearchHash = array();
                foreach ($aNewPhraseSearches as $iSearch => $aSearch) {
                    $sHash = serialize($aSearch);
                    if (isset($aSearchHash[$sHash])) unset($aNewPhraseSearches[$iSearch]);
                    else $aSearchHash[$sHash] = 1;
                }

                $aNewPhraseSearches = array_slice($aNewPhraseSearches, 0, 50);
            }

            // Re-group the searches by their score, junk anything over 20 as just not worth trying
            $aGroupedSearches = array();
            foreach ($aNewPhraseSearches as $aSearch) {
                $iRank = $aSearch->getRank();
                if ($iRank < $this->iMaxRank) {
                    if (!isset($aGroupedSearches[$iRank])) {
                        $aGroupedSearches[$iRank] = array();
                    }
                    $aGroupedSearches[$iRank][] = $aSearch;
                }
            }
            ksort($aGroupedSearches);

            $iSearchCount = 0;
            $aSearches = array();
            foreach ($aGroupedSearches as $iScore => $aNewSearches) {
                $iSearchCount += sizeof($aNewSearches);
                $aSearches = array_merge($aSearches, $aNewSearches);
                if ($iSearchCount > 50) break;
            }

            //if (CONST_Debug) _debugDumpGroupedSearches($aGroupedSearches, $aValidTokens);
        }

        // Revisit searches, drop bad searches and give penalty to unlikely combinations.
        $aGroupedSearches = array();
        foreach ($aSearches as $oSearch) {
            if (!$oSearch->isValidSearch()) {
                continue;
            }

            $iRank = $oSearch->getRank();
            if (!isset($aGroupedSearches[$iRank])) {
                $aGroupedSearches[$iRank] = array();
            }
            $aGroupedSearches[$iRank][] = $oSearch;
        }
        ksort($aGroupedSearches);

        return $aGroupedSearches;
    }

    /* Perform the actual query lookup.

        Returns an ordered list of results, each with the following fields:
            osm_type: type of corresponding OSM object
                        N - node
                        W - way
                        R - relation
                        P - postcode (internally computed)
            osm_id: id of corresponding OSM object
            class: general object class (corresponds to tag key of primary OSM tag)
            type: subclass of object (corresponds to tag value of primary OSM tag)
            admin_level: see http://wiki.openstreetmap.org/wiki/Admin_level
            rank_search: rank in search hierarchy
                        (see also http://wiki.openstreetmap.org/wiki/Nominatim/Development_overview#Country_to_street_level)
            rank_address: rank in address hierarchy (determines orer in address)
            place_id: internal key (may differ between different instances)
            country_code: ISO country code
            langaddress: localized full address
            placename: localized name of object
            ref: content of ref tag (if available)
            lon: longitude
            lat: latitude
            importance: importance of place based on Wikipedia link count
            addressimportance: cumulated importance of address elements
            extra_place: type of place (for admin boundaries, if there is a place tag)
            aBoundingBox: bounding Box
            label: short description of the object class/type (English only)
            name: full name (currently the same as langaddress)
            foundorder: secondary ordering for places with same importance
    */


    public function lookup()
    {
        if (!$this->sQuery && !$this->aStructuredQuery) return array();

        $oCtx = new SearchContext();

        if ($this->aRoutePoints) {
            $oCtx->setViewboxFromRoute(
                $this->oDB,
                $this->aRoutePoints,
                $this->aRouteWidth,
                $this->bBoundedSearch
            );
        } elseif ($this->aViewBox) {
            $oCtx->setViewboxFromBox($this->aViewBox, $this->bBoundedSearch);
        }
        if ($this->aExcludePlaceIDs) {
            $oCtx->setExcludeList($this->aExcludePlaceIDs);
        }
        if ($this->aCountryCodes) {
            $oCtx->setCountryList($this->aCountryCodes);
        }

        $sNormQuery = $this->normTerm($this->sQuery);
        $sLanguagePrefArraySQL = getArraySQL(
            array_map('getDBQuoted', $this->aLangPrefOrder)
        );

        $sQuery = $this->sQuery;
        if (!preg_match('//u', $sQuery)) {
            userError('Query string is not UTF-8 encoded.');
        }

        // Conflicts between US state abreviations and various words for 'the' in different languages
        if (isset($this->aLangPrefOrder['name:en'])) {
            $sQuery = preg_replace('/(^|,)\s*il\s*(,|$)/', '\1illinois\2', $sQuery);
            $sQuery = preg_replace('/(^|,)\s*al\s*(,|$)/', '\1alabama\2', $sQuery);
            $sQuery = preg_replace('/(^|,)\s*la\s*(,|$)/', '\1louisiana\2', $sQuery);
        }

        // Do we have anything that looks like a lat/lon pair?
        $sQuery = $oCtx->setNearPointFromQuery($sQuery);

        $aResults = array();
        if ($sQuery || $this->aStructuredQuery) {
            // Start with a single blank search
            $aSearches = array(new SearchDescription($oCtx));

            if ($sQuery) {
                $sQuery = $aSearches[0]->extractKeyValuePairs($sQuery);
            }

            $sSpecialTerm = '';
            if ($sQuery) {
                preg_match_all(
                    '/\\[([\\w ]*)\\]/u',
                    $sQuery,
                    $aSpecialTermsRaw,
                    PREG_SET_ORDER
                );
                foreach ($aSpecialTermsRaw as $aSpecialTerm) {
                    $sQuery = str_replace($aSpecialTerm[0], ' ', $sQuery);
                    if (!$sSpecialTerm) {
                        $sSpecialTerm = $aSpecialTerm[1];
                    }
                }
            }
            if (!$sSpecialTerm && $this->aStructuredQuery
                && isset($this->aStructuredQuery['amenity'])) {
                $sSpecialTerm = $this->aStructuredQuery['amenity'];
                unset($this->aStructuredQuery['amenity']);
            }

            if ($sSpecialTerm && !$aSearches[0]->hasOperator()) {
                $sSpecialTerm = pg_escape_string($sSpecialTerm);
                $sToken = chksql(
                    $this->oDB->getOne("SELECT make_standard_name('$sSpecialTerm')"),
                    'Cannot decode query. Wrong encoding?'
                );
                $sSQL = 'SELECT class, type FROM word ';
                $sSQL .= '   WHERE word_token in (\' '.$sToken.'\')';
                $sSQL .= '   AND class is not null AND class not in (\'place\')';
                if (CONST_Debug) var_Dump($sSQL);
                $aSearchWords = chksql($this->oDB->getAll($sSQL));
                $aNewSearches = array();
                foreach ($aSearches as $oSearch) {
                    foreach ($aSearchWords as $aSearchTerm) {
                        $oNewSearch = clone $oSearch;
                        $oNewSearch->setPoiSearch(
                            Operator::TYPE,
                            $aSearchTerm['class'],
                            $aSearchTerm['type']
                        );
                        $aNewSearches[] = $oNewSearch;
                    }
                }
                $aSearches = $aNewSearches;
            }

            // Split query into phrases
            // Commas are used to reduce the search space by indicating where phrases split
            if ($this->aStructuredQuery) {
                $aInPhrases = $this->aStructuredQuery;
                $bStructuredPhrases = true;
            } else {
                $aInPhrases = explode(',', $sQuery);
                $bStructuredPhrases = false;
            }

            // Convert each phrase to standard form
            // Create a list of standard words
            // Get all 'sets' of words
            // Generate a complete list of all
            $aTokens = array();
            $aPhrases = array();
            foreach ($aInPhrases as $iPhrase => $sPhrase) {
                $sPhrase = chksql(
                    $this->oDB->getOne('SELECT make_standard_name('.getDBQuoted($sPhrase).')'),
                    'Cannot normalize query string (is it a UTF-8 string?)'
                );
                if (trim($sPhrase)) {
                    $oPhrase = new Phrase($sPhrase, is_string($iPhrase) ? $iPhrase : '');
                    $oPhrase->addTokens($aTokens);
                    $aPhrases[] = $oPhrase;
                }
            }

            if (sizeof($aTokens)) {
                // Check which tokens we have, get the ID numbers
                $sSQL = 'SELECT word_id, word_token, word, class, type, country_code, operator, search_name_count';
                $sSQL .= ' FROM word ';
                $sSQL .= ' WHERE word_token in ('.join(',', array_map('getDBQuoted', $aTokens)).')';

                if (CONST_Debug) var_Dump($sSQL);

                $aValidTokens = array();
                $aDatabaseWords = chksql(
                    $this->oDB->getAll($sSQL),
                    'Could not get word tokens.'
                );
                $aWordFrequencyScores = array();
                foreach ($aDatabaseWords as $aToken) {
                    // Filter country tokens that do not match restricted countries.
                    if ($this->aCountryCodes
                        && $aToken['country_code']
                        && !in_array($aToken['country_code'], $this->aCountryCodes)
                    ) {
                        continue;
                    }

                    // Special terms need to appear in their normalized form.
                    if ($aToken['word'] && $aToken['class']) {
                        $sNormWord = $this->normTerm($aToken['word']);
                        if (strpos($sNormQuery, $sNormWord) === false) {
                            continue;
                        }
                    }

                    if (isset($aValidTokens[$aToken['word_token']])) {
                        $aValidTokens[$aToken['word_token']][] = $aToken;
                    } else {
                        $aValidTokens[$aToken['word_token']] = array($aToken);
                    }
                    $aWordFrequencyScores[$aToken['word_id']] = $aToken['search_name_count'] + 1;
                }
                if (CONST_Debug) var_Dump($aPhrases, $aValidTokens);

                // US ZIP+4 codes - if there is no token, merge in the 5-digit ZIP code
                foreach ($aTokens as $sToken) {
                    if (!isset($aValidTokens[$sToken]) && preg_match('/^([0-9]{5}) [0-9]{4}$/', $sToken, $aData)) {
                        if (isset($aValidTokens[$aData[1]])) {
                            foreach ($aValidTokens[$aData[1]] as $aToken) {
                                if (!$aToken['class']) {
                                    if (isset($aValidTokens[$sToken])) {
                                        $aValidTokens[$sToken][] = $aToken;
                                    } else {
                                        $aValidTokens[$sToken] = array($aToken);
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($aTokens as $sToken) {
                    // Unknown single word token with a number - assume it is a house number
                    if (!isset($aValidTokens[' '.$sToken]) && strpos($sToken, ' ') === false && preg_match('/^[0-9]+$/', $sToken)) {
                        $aValidTokens[' '.$sToken] = array(array('class' => 'place', 'type' => 'house', 'word_token' => ' '.$sToken));
                    }
                }

                // Any words that have failed completely?
                // TODO: suggestions

                $aGroupedSearches = $this->getGroupedSearches($aSearches, $aPhrases, $aValidTokens, $bStructuredPhrases);

                if ($this->bReverseInPlan) {
                    // Reverse phrase array and also reverse the order of the wordsets in
                    // the first and final phrase. Don't bother about phrases in the middle
                    // because order in the address doesn't matter.
                    $aPhrases = array_reverse($aPhrases);
                    $aPhrases[0]->invertWordSets();
                    if (sizeof($aPhrases) > 1) {
                        $aPhrases[sizeof($aPhrases)-1]->invertWordSets();
                    }
                    $aReverseGroupedSearches = $this->getGroupedSearches($aSearches, $aPhrases, $aValidTokens, false);

                    foreach ($aGroupedSearches as $aSearches) {
                        foreach ($aSearches as $aSearch) {
                            if (!isset($aReverseGroupedSearches[$aSearch->getRank()])) {
                                $aReverseGroupedSearches[$aSearch->getRank()] = array();
                            }
                            $aReverseGroupedSearches[$aSearch->getRank()][] = $aSearch;
                        }
                    }

                    $aGroupedSearches = $aReverseGroupedSearches;
                    ksort($aGroupedSearches);
                }
            } else {
                // Re-group the searches by their score, junk anything over 20 as just not worth trying
                $aGroupedSearches = array();
                foreach ($aSearches as $aSearch) {
                    if ($aSearch->getRank() < $this->iMaxRank) {
                        if (!isset($aGroupedSearches[$aSearch->getRank()])) $aGroupedSearches[$aSearch->getRank()] = array();
                        $aGroupedSearches[$aSearch->getRank()][] = $aSearch;
                    }
                }
                ksort($aGroupedSearches);
            }

            // Filter out duplicate searches
            $aSearchHash = array();
            foreach ($aGroupedSearches as $iGroup => $aSearches) {
                foreach ($aSearches as $iSearch => $aSearch) {
                    $sHash = serialize($aSearch);
                    if (isset($aSearchHash[$sHash])) {
                        unset($aGroupedSearches[$iGroup][$iSearch]);
                        if (sizeof($aGroupedSearches[$iGroup]) == 0) unset($aGroupedSearches[$iGroup]);
                    } else {
                        $aSearchHash[$sHash] = 1;
                    }
                }
            }

            if (CONST_Debug) _debugDumpGroupedSearches($aGroupedSearches, $aValidTokens);

            // Start the search process
            $iGroupLoop = 0;
            $iQueryLoop = 0;
            foreach ($aGroupedSearches as $iGroupedRank => $aSearches) {
                $iGroupLoop++;
                foreach ($aSearches as $oSearch) {
                    $iQueryLoop++;

                    if (CONST_Debug) {
                        echo "<hr><b>Search Loop, group $iGroupLoop, loop $iQueryLoop</b>";
                        _debugDumpGroupedSearches(array($iGroupedRank => array($oSearch)), $aValidTokens);
                    }

                    $aResults += $oSearch->query(
                        $this->oDB,
                        $aWordFrequencyScores,
                        $this->iMinAddressRank,
                        $this->iMaxAddressRank,
                        $this->iLimit
                    );

                    if ($iQueryLoop > 20) break;
                }

                if (sizeof($aResults) && ($this->iMinAddressRank != 0 || $this->iMaxAddressRank != 30)) {
                    // Need to verify passes rank limits before dropping out of the loop (yuk!)
                    // reduces the number of place ids, like a filter
                    // rank_address is 30 for interpolated housenumbers
                    $aFilterSql = array();
                    $sPlaceIds = Result::joinIdsByTable($aResults, Result::TABLE_PLACEX);
                    if ($sPlaceIds) {
                        $sSQL = 'SELECT place_id FROM placex ';
                        $sSQL .= 'WHERE place_id in ('.$sPlaceIds.') ';
                        $sSQL .= '  AND (';
                        $sSQL .= "         placex.rank_address between $this->iMinAddressRank and $this->iMaxAddressRank ";
                        if (14 >= $this->iMinAddressRank && 14 <= $this->iMaxAddressRank) {
                            $sSQL .= "     OR (extratags->'place') = 'city'";
                        }
                        if ($this->aAddressRankList) {
                            $sSQL .= '     OR placex.rank_address in ('.join(',', $this->aAddressRankList).')';
                        }
                        $sSQL .= ')';
                        $aFilterSql[] = $sSQL;
                    }
                    $sPlaceIds = Result::joinIdsByTable($aResults, Result::TABLE_POSTCODE);
                    if ($sPlaceIds) {
                        $sSQL = ' SELECT place_id FROM location_postcode lp ';
                        $sSQL .= 'WHERE place_id in ('.$sPlaceIds.') ';
                        $sSQL .= "  AND (lp.rank_address between $this->iMinAddressRank and $this->iMaxAddressRank ";
                        if ($this->aAddressRankList) {
                            $sSQL .= '     OR lp.rank_address in ('.join(',', $this->aAddressRankList).')';
                        }
                        $sSQL .= ') ';
                        $aFilterSql[] = $sSQL;
                    }

                    $aFilteredIDs = array();
                    if ($aFilterSql) {
                        $sSQL = join(' UNION ', $aFilterSql);
                        if (CONST_Debug) var_dump($sSQL);
                        $aFilteredIDs = chksql($this->oDB->getCol($sSQL));
                    }

                    $tempIDs = array();
                    foreach ($aResults as $oResult) {
                        if (($this->iMaxAddressRank == 30 &&
                             ($oResult->iTable == Result::TABLE_OSMLINE
                              || $oResult->iTable == Result::TABLE_AUX
                              || $oResult->iTable == Result::TABLE_TIGER))
                            || in_array($oResult->iId, $aFilteredIDs)
                        ) {
                            $tempIDs[$oResult->iId] = $oResult;
                        }
                    }
                    $aResults = $tempIDs;
                }

                if (sizeof($aResults)) break;
                if ($iGroupLoop > 4) break;
                if ($iQueryLoop > 30) break;
            }
        } else {
            // Just interpret as a reverse geocode
            $oReverse = new ReverseGeocode($this->oDB);
            $oReverse->setZoom(18);

            $oLookup = $oReverse->lookupPoint($oCtx->sqlNear, false);

            if (CONST_Debug) var_dump('Reverse search', $aLookup);

            if ($oLookup) {
                $aResults = array($oLookup->iId => $oLookup);
            }
        }

        // No results? Done
        if (!sizeof($aResults)) {
            if ($this->bFallback) {
                if ($this->fallbackStructuredQuery()) {
                    return $this->lookup();
                }
            }

            return array();
        }

        if ($this->aAddressRankList) {
            $this->oPlaceLookup->setAddressRankList($this->aAddressRankList);
        }
        $this->oPlaceLookup->setAllowedTypesSQLList($this->sAllowedTypesSQLList);
        $this->oPlaceLookup->setLanguagePreference($this->aLangPrefOrder);
        if ($oCtx->hasNearPoint()) {
            $this->oPlaceLookup->setAnchorSql($oCtx->sqlNear);
        }

        $aSearchResults = $this->oPlaceLookup->lookup($aResults);

        $aClassType = getClassTypesWithImportance();
        $aRecheckWords = preg_split('/\b[\s,\\-]*/u', $sQuery);
        foreach ($aRecheckWords as $i => $sWord) {
            if (!preg_match('/[\pL\pN]/', $sWord)) unset($aRecheckWords[$i]);
        }

        if (CONST_Debug) {
            echo '<i>Recheck words:<\i>';
            var_dump($aRecheckWords);
        }

        foreach ($aSearchResults as $iIdx => $aResult) {
            // Default
            $fDiameter = getResultDiameter($aResult);

            $aOutlineResult = $this->oPlaceLookup->getOutlines($aResult['place_id'], $aResult['lon'], $aResult['lat'], $fDiameter/2);
            if ($aOutlineResult) {
                $aResult = array_merge($aResult, $aOutlineResult);
            }

            if ($aResult['extra_place'] == 'city') {
                $aResult['class'] = 'place';
                $aResult['type'] = 'city';
                $aResult['rank_search'] = 16;
            }

            // Is there an icon set for this type of result?
            if (isset($aClassType[$aResult['class'].':'.$aResult['type']]['icon'])
                && $aClassType[$aResult['class'].':'.$aResult['type']]['icon']
            ) {
                $aResult['icon'] = CONST_Website_BaseURL.'images/mapicons/'.$aClassType[$aResult['class'].':'.$aResult['type']]['icon'].'.p.20.png';
            }

            if (isset($aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label'])
                && $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label']
            ) {
                $aResult['label'] = $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label'];
            } elseif (isset($aClassType[$aResult['class'].':'.$aResult['type']]['label'])
                && $aClassType[$aResult['class'].':'.$aResult['type']]['label']
            ) {
                $aResult['label'] = $aClassType[$aResult['class'].':'.$aResult['type']]['label'];
            }
            // if tag '&addressdetails=1' is set in query
            if ($this->bIncludeAddressDetails) {
                // getAddressDetails() is defined in lib.php and uses the SQL function get_addressdata in functions.sql
                $aResult['address'] = getAddressDetails($this->oDB, $sLanguagePrefArraySQL, $aResult['place_id'], $aResult['country_code'], $aResults[$aResult['place_id']]->iHouseNumber);
                if ($aResult['extra_place'] == 'city' && !isset($aResult['address']['city'])) {
                    $aResult['address'] = array_merge(array('city' => array_values($aResult['address'])[0]), $aResult['address']);
                }
            }

            $aResult['name'] = $aResult['langaddress'];

            if ($oCtx->hasNearPoint()) {
                $aResult['importance'] = 0.001;
                $aResult['foundorder'] = $aResult['addressimportance'];
            } else {
                // Adjust importance for the number of exact string matches in the result
                $aResult['importance'] *= $this->viewboxImportanceFactor(
                    $aResult['lon'],
                    $aResult['lat']
                );
                $aResult['importance'] = max(0.001, $aResult['importance']);
                $iCountWords = 0;
                $sAddress = $aResult['langaddress'];
                foreach ($aRecheckWords as $i => $sWord) {
                    if (stripos($sAddress, $sWord)!==false) {
                        $iCountWords++;
                        if (preg_match('/(^|,)\s*'.preg_quote($sWord, '/').'\s*(,|$)/', $sAddress)) $iCountWords += 0.1;
                    }
                }

                $aResult['importance'] = $aResult['importance'] + ($iCountWords*0.1); // 0.1 is a completely arbitrary number but something in the range 0.1 to 0.5 would seem right

                // secondary ordering (for results with same importance (the smaller the better):
                // - approximate importance of address parts
                $aResult['foundorder'] = -$aResult['addressimportance']/10;
                // - number of exact matches from the query
                $aResult['foundorder'] -= $aResults[$aResult['place_id']]->iExactMatches;
                // - importance of the class/type
                if (isset($aClassType[$aResult['class'].':'.$aResult['type']]['importance'])
                    && $aClassType[$aResult['class'].':'.$aResult['type']]['importance']
                ) {
                    $aResult['foundorder'] += 0.0001 * $aClassType[$aResult['class'].':'.$aResult['type']]['importance'];
                } else {
                    $aResult['foundorder'] += 0.01;
                }
            }
            if (CONST_Debug) var_dump($aResult);
            $aSearchResults[$iIdx] = $aResult;
        }
        uasort($aSearchResults, 'byImportance');

        $aOSMIDDone = array();
        $aClassTypeNameDone = array();
        $aToFilter = $aSearchResults;
        $aSearchResults = array();

        if (CONST_Debug) var_dump($aToFilter);

        $bFirst = true;
        foreach ($aToFilter as $aResult) {
            $this->aExcludePlaceIDs[$aResult['place_id']] = $aResult['place_id'];
            if ($bFirst) {
                $fLat = $aResult['lat'];
                $fLon = $aResult['lon'];
                if (isset($aResult['zoom'])) $iZoom = $aResult['zoom'];
                $bFirst = false;
            }
            if (!$this->oPlaceLookup->doDeDupe() || (!isset($aOSMIDDone[$aResult['osm_type'].$aResult['osm_id']])
                && !isset($aClassTypeNameDone[$aResult['osm_type'].$aResult['class'].$aResult['type'].$aResult['name'].$aResult['admin_level']]))
            ) {
                $aOSMIDDone[$aResult['osm_type'].$aResult['osm_id']] = true;
                $aClassTypeNameDone[$aResult['osm_type'].$aResult['class'].$aResult['type'].$aResult['name'].$aResult['admin_level']] = true;
                $aSearchResults[] = $aResult;
            }

            // Absolute limit on number of results
            if (sizeof($aSearchResults) >= $this->iFinalLimit) break;
        }

        if (CONST_Debug) var_dump($aSearchResults);
        return $aSearchResults;
    } // end lookup()
} // end class
