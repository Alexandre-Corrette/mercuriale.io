<?php

declare(strict_types=1);

namespace App\Service\Categorisation;

/**
 * Baseline déterministe : devine la catégorie par mots-clés présents dans la
 * désignation. Gratuit, transparent, sans appel externe.
 *
 * L'ORDRE des règles = priorité (premier match gagne). Il est volontaire :
 * Alcool / Hygiène / Consommables passent AVANT l'épicerie, sinon des mots
 * comme "SUCRE", "HUILE" ou "CREME" enverraient des spiritueux/produits
 * d'entretien dans la mauvaise famille.
 *
 * Couvre la majorité du catalogue ; la traîne non couverte renvoie null
 * (à traiter par un fallback LLM ou la revue humaine).
 */
final class KeywordCategoryGuesser implements CategoryGuesserInterface
{
    /**
     * Règles ordonnées : [codeCategorie, [mots-clés]].
     *
     * @var array<int, array{0: string, 1: list<string>}>
     */
    private const RULES = [
        ['HYGIENE', ['NETT', 'DEGRAISS', 'VAISS', 'JAVEL', 'DETERGENT', 'LESSIVE', 'DR BECHER', 'JEX', 'SAVON', 'DESINFECT']],
        ['CONSOMMABLES', ['PAILLE', 'SERVIETTE', 'SERV DBLE', 'SERV PT', 'GANT', 'CUILLER', 'FOURCHETTE', 'GOBELET', 'GN1', 'GN/', 'METROPRO', 'HERM GN', 'CARTOUCHE', 'BARQUETTE', 'FILM ALU', 'ESSUIE', 'SOPALIN', 'SAC POUBELLE', 'CO2']],
        ['MAREE', ['SAUM', 'DAURADE', 'DORADE', 'THON', 'ALBACORE', 'ENCORNET', 'GAMBAS', 'CREVETTE', 'MOULE', ' MLE ', 'SEICHE', 'POULPE', 'CALAMAR', 'HUITRE', 'CABILLAUD', 'COLIN', 'LOTTE', 'SARDINE', 'MAQUEREAU', 'LONGE ALBA', 'ENCRE DE SEICHE']],
        ['VIANDES', ['ENTRECOTE', 'MAGRET', 'POULET', ' PLT ', 'VOLAILLE', 'PORC', 'BOEUF', 'VBF', 'VEAU', ' VX ', 'AGNEAU', 'CANARD', 'CHORIZO', 'JAMBON', 'SERRANO', ' JB ', 'SAUCISS', 'LARDON', ' LARD', 'BACON', 'CHARO', 'RTK', 'AIGUILLET', 'ESCALOPE', 'GORGE', 'CUISSE', 'FOIE', 'MERGUEZ', 'STEAK', 'EGRENE', 'ENTRECOT']],
        ['CREMERIE', ['CREME', 'BEURRE', 'LAIT', 'OEUF', 'FROMAGE', 'FETA', 'CHEDDAR', 'CAMEMBERT', 'CHEVRE', 'BURRATA', 'MOZZA', 'MASCARPONE', 'CREAM CHEESE', 'CANTAL', 'MORBIER', 'BREBIS', 'YAOURT', 'EMMENTAL', 'PARMESAN', 'GRUYERE', 'RACLETTE']],
        ['BOISSONS_SA', ['EAU ', 'BADOIT', 'ABATILLES', 'PERRIER', 'EVIAN', 'VITTEL', 'COCA', 'FANTA', 'SPRITE', 'ORANGINA', 'SCHWEPPES', 'SCHWEP', 'PEPSI', 'CARAIBOS', 'PAGO', 'GRANINI', ' JUS', 'NECTAR', 'SIROP', 'RED BULL', 'LIMONADE', 'TONIC', 'FUZETEA', 'LIPTON', 'ICE TEA', ' THE ', 'CAFE', 'PUREE DE', 'MONIN', 'TEISSEIRE']],
        ['EPICERIE_SUCREE', ['CHOCO', 'CHOC ', 'COUV ', 'PRALIN', 'AMANDE', 'NOISETTE', 'PISTACHE', 'VANILLE', 'SUCRE', 'CASSONADE', 'CANADOU', 'CONFITURE', 'MIEL', 'NUTELLA', 'GLUCOSE', 'NAPPAGE', 'GLACAGE', 'SPECULOOS', 'CARAMEL', 'PALET CHOCO']],
        ['EPICERIE_SALEE', ['RIZ', 'PATE', 'LINGUINE', 'SPAGHET', 'PENNE', 'TAGLIA', 'FARINE', 'MAIZENA', 'CHAPELURE', 'HUILE', 'VINAIGRE', 'MOUTARD', 'KETCHUP', 'MAYO', 'SAUCE', 'SEL ', 'FLEUR DE SEL', 'POIVR', 'POIV ', 'CUMIN', 'CURCUMA', 'CURRY', 'CURY', 'THYM', 'ROMARIN', 'PAPRIKA', 'EPICE', 'BAIES', 'CORNICH', 'OLIVE', 'CAPRE', 'PIQUILLO', 'BOUILLON', 'SEMOULE', 'COUSCOUS', 'LENTILLE', 'HARICOT', 'TERIYAKI', 'MIRIN', 'SOJA', 'CAJUN', 'GUERANDE', 'BALSAM', 'KETCH']],
        ['FRUITS_LEGUMES', ['ASPERGE', 'SALADE', 'CAROTTE', 'POMME DE TERRE', 'OIGNON', ' AIL ', 'COURGETTE', 'AUBERGINE', 'CHAMPIGNON', 'POIREAU', 'EPINARD', 'BROCOLI', 'CHOU', 'CONCOMBRE', 'RADIS', 'BETTERAVE', 'CITRON', 'ORANGE', 'BANANE', 'FRAISE', 'FRAMBOISE', 'POMME ', 'POIRE', 'RAISIN', 'MELON', 'PASTEQUE', 'ANANAS', 'MANGUE', 'AVOCAT', 'PERSIL', 'CORIANDRE', 'BASILIC']],
    ];

    /** @var list<string> Mots-clés "spiritueux/bière/vin" déclenchant ALCOOLS. */
    private const ALCOOL_KEYWORDS = [
        'WHISK', 'VODKA', ' GIN ', 'RHUM', ' RON ', 'TEQUILA', 'COGNAC', 'ARMAGNAC', 'CALVADOS',
        'LIQUEUR', 'BIERE', ' IPA', ' FUT ', 'PROSECCO', 'CHAMPAGNE', 'CREMANT', 'APEROL', 'CAMPARI',
        'RICARD', 'PASTIS', 'MARTINI', 'BAILEYS', 'COINTREAU', 'CURACAO', 'JAGER', 'KAHLUA', 'LIMONCELLO',
        'BOURBON', 'CACHACA', 'GET 27', 'GET27', 'PICON', 'SPRITZ', 'AMARETTO', 'TRIPLE SEC', 'BITTER',
        'ANGOSTURA', 'DESPERADOS', 'CORONA', 'HOEGAARD', 'GRIMBERGEN', 'PIETRA', 'HAVANA', 'CHIVAS',
        'BALLANTINE', 'SMIRNOFF', 'BOMBAY', 'ERISTOFF', 'SOBIESKI', 'STOLI', 'WYBOROWA', 'CIROC', 'PADDY',
        'JAMESON', 'BUFFALO TRACE', 'DON PAPA', 'JAGERMEISTER',
    ];

    public function guess(string $designation): ?string
    {
        $s = $this->normalize($designation);
        if ($s === '') {
            return null;
        }

        // Garde-fou : "sans alcool" / "0° alc" → boisson, AVANT toute détection d'alcool.
        foreach (['SANS ALCOOL', '0 ALC', 'ZERO ALCOOL', '0°ALC'] as $kw) {
            if (str_contains($s, $kw)) {
                return 'BOISSONS_SA';
            }
        }

        // Degré d'alcool : "40°", "37.5°", "44.7D 70CL", "17,9D" → ALCOOLS.
        if (
            preg_match('/\d{1,2}(?:[.,]\d+)?\s*°/u', $designation) === 1
            || preg_match('/\b\d{1,2}(?:[.,]\d+)?D\b/', $s) === 1
        ) {
            return 'ALCOOLS';
        }

        foreach (self::ALCOOL_KEYWORDS as $kw) {
            if (str_contains($s, $kw)) {
                return 'ALCOOLS';
            }
        }

        foreach (self::RULES as [$code, $keywords]) {
            foreach ($keywords as $kw) {
                if (str_contains($s, $kw)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Majuscule + suppression des accents, espaces encadrants pour fiabiliser
     * les mots-clés à délimiteur (" PLT ", " JB ", "EAU ").
     */
    private function normalize(string $designation): string
    {
        $s = mb_strtoupper(trim($designation), 'UTF-8');
        $s = strtr($s, [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O',
            'Û' => 'U', 'Ù' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Œ' => 'OE',
        ]);

        return ' ' . $s . ' ';
    }
}
