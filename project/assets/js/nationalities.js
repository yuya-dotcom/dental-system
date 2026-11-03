/* =============================================================
   assets/js/nationalities.js
   Single nationality list shared by registration (portal-login.php)
   and My Profile (portal-dashboard.php).

   <select data-nationality-options> is filled with these options when
   this script runs. Load it BEFORE custom-select.js so the searchable
   .modal-cs dropdown is built with the full list.
   ============================================================= */
window.ES_NATIONALITIES = [
    'Filipino',
    'Afghan', 'Albanian', 'Algerian', 'American', 'Andorran', 'Angolan', 'Antiguan', 'Argentine',
    'Armenian', 'Australian', 'Austrian', 'Azerbaijani', 'Bahamian', 'Bahraini',
    'Bangladeshi', 'Barbadian', 'Belarusian', 'Belgian', 'Belizean', 'Beninese',
    'Bhutanese', 'Bolivian', 'Bosnian', 'Botswanan', 'Brazilian', 'British', 'Bruneian',
    'Bulgarian', 'Burkinabe', 'Burundian', 'Cambodian', 'Cameroonian', 'Canadian',
    'Cape Verdean', 'Central African', 'Chadian', 'Chilean', 'Chinese', 'Colombian',
    'Comorian', 'Congolese', 'Costa Rican', 'Croatian', 'Cuban', 'Cypriot', 'Czech',
    'Danish', 'Djiboutian', 'Dominican', 'Dutch', 'Ecuadorian', 'Egyptian',
    'Emirati', 'Equatorial Guinean', 'Eritrean', 'Estonian', 'Ethiopian', 'Fijian',
    'Finnish', 'French', 'Gabonese', 'Gambian', 'Georgian', 'German', 'Ghanaian',
    'Greek', 'Grenadian', 'Guatemalan', 'Guinea-Bissauan', 'Guinean', 'Guyanese', 'Haitian',
    'Honduran', 'Hong Konger', 'Hungarian', 'I-Kiribati', 'Icelandic', 'Indian', 'Indonesian',
    'Iranian', 'Iraqi', 'Irish', 'Israeli', 'Italian', 'Ivorian', 'Jamaican', 'Japanese',
    'Jordanian', 'Kazakhstani', 'Kenyan', 'Kittitian', 'Korean', 'Kosovar', 'Kuwaiti', 'Kyrgyz',
    'Laotian', 'Latvian', 'Lebanese', 'Liberian', 'Libyan', 'Liechtensteiner', 'Lithuanian',
    'Luxembourgish', 'Macanese', 'Macedonian', 'Malagasy', 'Malawian', 'Malaysian', 'Maldivian',
    'Malian', 'Maltese', 'Marshallese', 'Mauritanian', 'Mauritian', 'Mexican', 'Micronesian',
    'Moldovan', 'Monacan', 'Mongolian', 'Montenegrin', 'Moroccan', 'Mosotho', 'Mozambican',
    'Myanmarese', 'Namibian', 'Nauruan', 'Nepalese', 'New Zealander', 'Ni-Vanuatu', 'Nicaraguan',
    'Nigerian', 'Nigerien', 'North Korean', 'Norwegian', 'Omani', 'Pakistani', 'Palauan',
    'Palestinian', 'Panamanian', 'Papua New Guinean', 'Paraguayan', 'Peruvian', 'Polish',
    'Portuguese', 'Puerto Rican', 'Qatari', 'Romanian', 'Russian', 'Rwandan', 'Saint Lucian',
    'Salvadoran', 'Samoan', 'Sammarinese', 'Sao Tomean', 'Saudi', 'Senegalese', 'Serbian',
    'Seychellois', 'Sierra Leonean', 'Singaporean', 'Slovak', 'Slovenian', 'Solomon Islander',
    'Somali', 'South African', 'South Korean', 'South Sudanese', 'Spanish', 'Sri Lankan',
    'Sudanese', 'Surinamese', 'Swazi', 'Swedish', 'Swiss', 'Syrian', 'Taiwanese', 'Tajik',
    'Tanzanian', 'Thai', 'Timorese', 'Togolese', 'Tongan', 'Trinidadian', 'Tunisian', 'Turkish',
    'Turkmen', 'Tuvaluan', 'Ugandan', 'Ukrainian', 'Uruguayan', 'Uzbek', 'Vatican', 'Venezuelan',
    'Vietnamese', 'Vincentian', 'Yemeni', 'Zambian', 'Zimbabwean'
];

(function () {
    'use strict';
    document.querySelectorAll('select[data-nationality-options]').forEach(function (sel) {
        window.ES_NATIONALITIES.forEach(function (n) { sel.add(new Option(n, n)); });
    });
})();

/**
 * Select a saved nationality. A legacy value that isn't in the list
 * (e.g. "filipino", "Filipino-American") is kept as its own option so
 * saving the profile never silently replaces it.
 */
window.setNationalityValue = function (sel, value) {
    if (!sel) return;
    const v = value == null ? '' : String(value);
    if (v !== '' && !Array.from(sel.options).some(function (o) { return o.value === v; })) {
        sel.add(new Option(v, v), 1); // right after the placeholder
        if (typeof sel._csRebuild === 'function') sel._csRebuild();
    }
    sel.selectedIndex = Math.max(0, Array.from(sel.options).findIndex(function (o) { return o.value === v; }));
    if (typeof sel._csRefresh === 'function') sel._csRefresh();
};
