<?php
/**
 * Plugin Name: Yoast Schema Extender — Agency Pack (Multi-Location + FAQ Builder)
 * Description: Adds industry-aware, LLM-friendly schema on top of Yoast with a settings UI and per-post FAQ metabox. Supports Service Areas, 3-tier LocalBusiness subtypes, Import/Export, topic mentions, optional Multi-Location branches (inherit hours/phone/email). Coexists with Yoast; avoids duplication. Per-post Overrides metabox removed to keep non-tech users safe.
 * Version: 2.5.4
 * Author: Thomas Digital
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class YSE_Agency_UI {
    const OPT_KEY = 'yse_settings';
    private static $instance = null;

    public static function instance(){
        return self::$instance ?: self::$instance = new self();
    }

    private function __construct(){
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_enqueue_scripts', [$this,'admin_assets']);
        add_action('admin_notices', [$this,'maybe_show_dependency_notice']);

        // Keep ONLY the FAQ Builder metabox
        add_action('add_meta_boxes', [$this,'add_faq_metaboxes']);
        add_action('save_post', [$this,'save_faq_meta'], 10, 2);

        // Minimal admin list columns: show only FAQ count
        add_action('admin_init', [$this,'register_admin_columns']);

        // Import handler
        add_action('admin_post_yse_import', [$this,'handle_import']);

        // Hook into Yoast graph & filters
        add_action('plugins_loaded', function(){
            if ($this->yoast_available()){
                $this->hook_schema_filters();
                add_filter('wpseo_schema_graph', [$this,'inject_faq_into_graph'], 30, 2);
            }
        });
    }

    /* ---------------- Dependency checks ---------------- */

    private function yoast_available(){
        return class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece') || has_filter('wpseo_schema_graph');
    }
    private function yoast_local_available(){
        return defined('WPSEO_LOCAL_VERSION') || class_exists('\Yoast\WP\Local\Main');
    }
    public function maybe_show_dependency_notice(){
        if (!current_user_can('manage_options')) return;
        if (!$this->yoast_available()){
            echo '<div class="notice notice-error"><p><strong>Yoast Schema Extender:</strong> Yoast SEO not detected. Install & activate Yoast SEO to enable schema extensions.</p></div>';
        }
    }

    /* ---------------- Admin menu / assets ---------------- */

    public function admin_menu(){
        add_options_page('Schema Extender','Schema Extender','manage_options','yse-settings',[$this,'render_settings_page']);
    }

    public function admin_assets($hook){
        $is_settings = ($hook === 'settings_page_yse-settings');
        $is_post_edit = ($hook === 'post.php' || $hook === 'post-new.php');
        if (!$is_settings && !$is_post_edit) return;

        wp_enqueue_media();

        // JS (inline registered for convenience)
        wp_register_script('yse-admin', '', ['jquery'], '2.5.4', true);
        wp_enqueue_script('yse-admin');

        // CSS from file (added in assets/)
        wp_enqueue_style('yse-admin-css', plugins_url('assets/admin.css', __FILE__), [], '2.5.4');

        $inline_js = <<<'JS'
/* ===== YSE Admin JS (fixed) ===== */
const YSE_LB_TREE = {
  'ProfessionalService': { children: ['AccountingService','FinancialService','InsuranceAgency','LegalService','RealEstateAgent'] },
  'MedicalOrganization': { children: ['Dentist','Physician','Pharmacy','VeterinaryCare'] },
  'HealthAndBeautyBusiness': { children: ['HairSalon','NailSalon','DaySpa'] },
  'HomeAndConstructionBusiness': { children: ['Electrician','GeneralContractor','HVACBusiness','Locksmith','MovingCompany','Plumber','RoofingContractor'] },
  'AutomotiveBusiness': { children: ['AutoDealer','AutoRepair','AutoBodyShop','TireShop'] },
  'FoodEstablishment': { children: ['Restaurant','Bakery','CafeOrCoffeeShop','BarOrPub','IceCreamShop'] },
  'LodgingBusiness': { children: ['Hotel','Motel','Resort'] },
  'Store': { children: ['BookStore','ClothingStore','ComputerStore','ElectronicsStore','FurnitureStore','GardenStore','GroceryStore','HardwareStore','JewelryStore','MobilePhoneStore','SportingGoodsStore','TireShop','ToyStore'] },
  'Restaurant': { children: ['FastFoodRestaurant','SeafoodRestaurant'] },
  'AutoDealer': { children: ['MotorcycleDealer'] },
  'GroceryStore': { children: ['Supermarket'] }
};

function ysePopulateSelect(sel, list, placeholder){
  if (!sel) return;
  sel.innerHTML = '';
  const opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = placeholder || '(Optional)';
  sel.appendChild(opt0);
  if (!list || !list.length){
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  list.forEach(v=>{
    const o = document.createElement('option');
    o.value = v;
    o.textContent = v;
    sel.appendChild(o);
  });
}

function yseInitSubtypeCascades(){
  const main = document.getElementById('lb_subtype');
  const sub2 = document.getElementById('lb_subtype2');
  const sub3 = document.getElementById('lb_subtype3');
  if(!main || !sub2 || !sub3) return;

  const refresh = ()=>{
    const t1 = main.value || '';
    const branch = YSE_LB_TREE[t1];
    const tier2 = branch && branch.children ? branch.children : [];
    ysePopulateSelect(sub2, tier2, '(Subtype — optional)');

    const t2 = sub2.value || '';
    const branch2 = YSE_LB_TREE[t2];
    const tier3 = branch2 && branch2.children ? branch2.children : [];
    ysePopulateSelect(sub3, tier3, '(Subtype level 3 — optional)');
  };

  // initial
  refresh();

  main.addEventListener('change', ()=>{
    sub2.setAttribute('data-current','');
    sub3.setAttribute('data-current','');
    refresh();
  });
  sub2.addEventListener('change', ()=>{
    sub3.setAttribute('data-current','');
    const t2 = sub2.value || '';
    const branch2 = YSE_LB_TREE[t2];
    const tier3 = branch2 && branch2.children ? branch2.children : [];
    ysePopulateSelect(sub3, tier3, '(Subtype level 3 — optional)');
  });
}

function yseInitMultiLocation(){
  const wrap = document.getElementById('yse-ml-wrap');
  if (!wrap) return;

  wrap.addEventListener('click', function(e){
    const btn = e.target.closest('[data-yse-act]');
    if (!btn) return;
    e.preventDefault();

    const act = btn.getAttribute('data-yse-act');
    if (act === 'add'){
      const list = wrap.querySelector('.yse-ml-list');
      const tmpl = wrap.querySelector('template');
      const idx = Date.now();
      const html = tmpl.innerHTML.replace(/__IDX__/g, String(idx));
      const div = document.createElement('div');
      div.className = 'yse-card yse-ml-item';
      div.innerHTML = html;
      list.appendChild(div);
      return;
    }
    if (act === 'remove'){
      const card = btn.closest('.yse-ml-item');
      if (card) card.remove();
      return;
    }
    if (act === 'media'){
      const fieldId = btn.getAttribute('data-target');
      const input = wrap.querySelector('#'+fieldId);
      if (typeof wp === 'undefined' || !wp.media) { alert('Media Library not available.'); return; }
      const frame = wp.media({title:'Select Image', button:{text:'Use this'}, multiple:false});
      frame.on('select', function(){
        const att = frame.state().get('selection').first().toJSON();
        input.value = att.url;
      });
      frame.open();
      return;
    }
    if (act === 'hours-eg'){
      const ta = btn.closest('.yse-field').querySelector('textarea');
      if (ta){
        const sample = [{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"}];
        ta.value = JSON.stringify(sample, null, 2);
      }
      return;
    }
  });
}

// FAQ Builder UI
function yseInitFaqBuilder(){
    document.addEventListener('click', function(e){
        const add = e.target.closest('[data-yse-faq-add]');
        const del = e.target.closest('[data-yse-faq-del]');
        const up  = e.target.closest('[data-yse-faq-up]');
        const dn  = e.target.closest('[data-yse-faq-dn]');

        if (add){
        e.preventDefault();
        const wrap = add.closest('.yse-faq-wrap');
        const list = wrap.querySelector('.yse-faq-list');
        const tmpl = wrap.querySelector('template');
        const idx = Date.now();
        const html = tmpl.innerHTML.replace(/__IDX__/g, String(idx));
        const div = document.createElement('div');
        div.className = 'yse-faq-item';
        div.innerHTML = html;
        list.appendChild(div);
        return;
        }
        if (del){
        e.preventDefault();
        const item = del.closest('.yse-faq-item');
        if (item) item.remove();
        return;
        }
        if (up || dn){
        e.preventDefault();
        const handle = up || dn;
        const item = handle.closest('.yse-faq-item');
        if (!item) return;
        const list = item.parentElement;
        if (up && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (dn && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        return;
        }
    });
}

jQuery(function($){
  // Media picker for main logo
  $(document).on('click', '.yse-media', function(e){
    e.preventDefault();
    const field = $('#'+$(this).data('target'));
    if (typeof wp === 'undefined' || !wp.media) {
      alert('Media Library not available. Please ensure you are in the WP admin and media scripts are loaded.');
      return;
    }
    const frame = wp.media({title:'Select Logo', button:{text:'Use this'}, multiple:false});
    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      field.val(att.url).trigger('change');
    });
    frame.open();
  });

  function fill(id, sample){
    const el = document.getElementById(id);
    if (!el) return;
    el.value = JSON.stringify(sample, null, 2);
  }

  // Insert Example links
  $(document).on('click','[data-yse-example="identifier"]', function(e){ e.preventDefault(); fill('identifier', [{"@type":"PropertyValue","propertyID":"DUNS","value":"123456789"}]); });
    // Insert Example: Opening Hours — Mon–Fri 09:00–17:00, Sat/Sun closed
    $(document).on('click','[data-yse-example="opening_hours"]', function(e){
    e.preventDefault();
    const sample = [
        {
        "@type": "OpeningHoursSpecification",
        "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"],
        "opens": "09:00",
        "closes": "17:00"
        },
        // {
        // Weekend closed template.
        // Some validators prefer you simply OMIT closed days entirely.
        // If a tool complains about 00:00–00:00, delete this weekend block.
        // "@type": "OpeningHoursSpecification",
        // "dayOfWeek": ["Saturday","Sunday"],
        // "opens": "00:00",
        // "closes": "00:00"
        // }
    ];
    const el = document.getElementById('opening_hours');
    if (!el) return;
    el.value = JSON.stringify(sample, null, 2);
    });
  $(document).on('click','[data-yse-example="entity_mentions"]', function(e){ e.preventDefault(); fill('entity_mentions', [{"@id":"https://en.wikipedia.org/wiki/Web_design"},{"@id":"https://www.wikidata.org/wiki/Q16674915"}]); });

  yseInitSubtypeCascades();
  yseInitMultiLocation();
  yseInitFaqBuilder();
});
JS;
        wp_add_inline_script('yse-admin', $inline_js);
    }

    /* ---------------- Settings & fields ---------------- */

    public function register_settings(){
        register_setting(self::OPT_KEY, self::OPT_KEY, [$this,'sanitize_settings']);

        add_settings_section('yse_main', 'Organization & LocalBusiness', function(){
            echo '<p>Configure your primary entity. We merge with Yoast Site Representation unless override is enabled (see Compatibility).</p>';
        }, 'yse-settings');

        // Main org/local fields (sameAs included)
        $fields = [
            ['org_name','Organization Name','text','', 'The official business name (as customers see it).'],
            ['org_url','Organization URL','url','', 'Your primary website address (home page).'],
            ['org_logo','Logo URL','text','', 'Square/rectangular logo. Use “Select”.'],
            ['org_email','Public Email','text','', 'ELI5: Customers can email this address. Leave blank if you don’t publish email.'],
            ['telephone','Telephone (E.164 preferred)','text','', 'Phone number like +1-916-555-1212.'],
            ['same_as','sameAs Profiles (one URL per line)','textarea','', 'ELI5: Paste links to official profiles (Google Business Profile, Facebook, LinkedIn, Yelp). One per line.'],
            ['identifier','Identifiers (JSON array)','textarea','identifier', 'ELI5: Extra IDs that prove who you are. Click “Insert example”.'],
            ['is_local','Is LocalBusiness?','checkbox','', 'Tick if you serve a local area or have a physical location.'],
            ['lb_subtype','LocalBusiness Subtype (Tier 1)','select', self::local_subtypes(), 'Pick the closest family for your business.'],
            ['lb_subtype2','Subtype (Tier 2 • optional)','select', [], 'Changes based on Tier 1.'],
            ['lb_subtype3','Subtype (Tier 3 • optional)','select', [], 'Appears only if the chosen Tier 2 has children.'],
            ['addr_street','Street Address','text','', 'Street and unit/suite.'],
            ['addr_city','City / Locality','text','', 'City name (e.g., Sacramento).'],
            ['addr_region','Region / State','text','', 'State/region (e.g., CA).'],
            ['addr_postal','Postal Code','text','', 'ZIP or postal code.'],
            ['addr_country','Country Code (e.g., US)','text','', 'Two-letter ISO-3166 code.'],
            ['geo_lat','Geo Latitude','text','', 'Optional. Decimal latitude (e.g., 38.5816).'],
            ['geo_lng','Geo Longitude','text','', 'Optional. Decimal longitude (e.g., -121.4944).'],
            ['opening_hours','Opening Hours (JSON array)','textarea','opening_hours', 'ELI5: Business hours. Use “Insert example”.'],
            ['service_area','Service Area – Cities (one per line)','textarea','', 'ELI5: Type the cities you serve. One per line.'],
        ];
        foreach ($fields as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_main', [
                'key'=>$f[0],'type'=>$f[2],'options'=>$f[3]??null,'help'=>$f[4]??''
            ]);
        }

        add_settings_section('yse_ml', 'Multiple Locations (Optional)', function(){
            echo '<p>Enable if the business has multiple branches/offices. We create separate <code>LocalBusiness</code> nodes with <code>parentOrganization</code>. On a matching location page, the WebPage is “about” that branch. Validate if you also run Yoast Local SEO to avoid duplication.</p>';
        }, 'yse-settings');
        add_settings_field('ml_enabled','Enable multiple locations',[$this,'render_field'],'yse-settings','yse_ml',['key'=>'ml_enabled','type'=>'checkbox','help'=>'Adds a repeatable list of locations below.']);
        add_settings_field('ml_locations','Locations',[$this,'render_ml_repeater'],'yse-settings','yse_ml');

        add_settings_section('yse_intent', 'Page Intent Detection', function(){
            echo '<p>Tell search engines what a page is. First match wins. Keep it honest.</p>';
        }, 'yse-settings');
        $intent = [
            ['slug_about','About slug (e.g., about)','text','', 'If your About page is /about-us, enter <code>about-us</code>.'],
            ['slug_contact','Contact slug (e.g., contact)','text','', 'If your Contact page is /get-in-touch, enter <code>get-in-touch</code>.'],
            ['faq_shortcode','FAQ shortcode tag (e.g., faq)','text','', 'If your FAQ uses a shortcode like [faq], enter the tag here. (WebPage @type only)'],
            ['howto_shortcode','HowTo shortcode tag (e.g., howto)','text','', 'If your how-to uses [howto], enter the tag here.'],
            ['extra_faq_slug','Additional FAQ page slug','text','', 'If you have a separate FAQs page, enter its slug.'],
        ];
        foreach ($intent as $f){
            add_settings_field($f[0], $f[1], [$this,'render_field'], 'yse-settings', 'yse_intent', [
                'key'=>$f[0],'type'=>$f[2],'help'=>$f[4]??''
            ]);
        }

        add_settings_section('yse_cpt', 'CPT → Schema Mapping', function(){
            echo '<p>One per line, format: <span class="yse-badge">cpt:Type</span> (e.g., <code>services:Service</code>, <code>locations:Place</code>, <code>team:Person</code>, <code>software:SoftwareApplication</code>).</p>';
        }, 'yse-settings');
        add_settings_field('cpt_map','Mappings',[$this,'render_field'],'yse-settings','yse_cpt',['key'=>'cpt_map','type'=>'textarea','help'=>'Enter one mapping per line.']);

        add_settings_section('yse_mentions', 'Topic Mentions (LLM-friendly)', function(){
            echo '<p>JSON array of <code>{ "@id": "https://..." }</code> links (Wikipedia/Wikidata) that describe your topics.</p>';
        }, 'yse-settings');
        add_settings_field('entity_mentions','about/mentions JSON',[$this,'render_field'],'yse-settings','yse_mentions',[
            'key'=>'entity_mentions','type'=>'textarea','options'=>'entity_mentions','help'=>'We add to both about and mentions.'
        ]);

        add_settings_section('yse_faq', 'FAQ Builder (Per-Post)', function(){
            echo '<p>Add a simple FAQ metabox to selected post types. Editors enter questions & answers; we emit a valid <code>FAQPage</code> JSON-LD. Nothing is selected by default.</p>';
        }, 'yse-settings');
        add_settings_field('faq_post_types','Enable on post types',[$this,'render_faq_post_types_field'],'yse-settings','yse_faq');

        add_settings_section('yse_overrides','Compatibility', function(){
            echo '<p>By default, we <strong>merge</strong> with Yoast Site Representation (fill blanks, merge lists). Turn on override to let your values win.</p>';
        }, 'yse-settings');
        add_settings_field('override_org','Allow overriding Yoast Site Representation',[$this,'render_field'],'yse-settings','yse_overrides',['key'=>'override_org','type'=>'checkbox','help'=>'Leave OFF unless Yoast values are missing/wrong.']);
    }

    public function render_field($args){
        $key = $args['key']; $type = $args['type']; $options = $args['options'] ?? null; $help = $args['help'] ?? '';
        $opt = get_option(self::OPT_KEY, []);
        $val = isset($opt[$key]) ? $opt[$key] : '';

        echo '<div class="yse-field">';
        if ($type==='text' || $type==='url'){
            printf('<input type="text" class="regular-text" name="%s[%s]" id="%s" value="%s"/>',
                esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_attr($val));
            if ($key==='org_logo'){
                echo ' <button class="button yse-media" data-target="'.esc_attr($key).'">Select</button>';
            }
        } elseif ($type==='textarea'){
            $is_json_field = in_array($key, ['identifier','opening_hours','entity_mentions'], true);
            if ($is_json_field){
                $display = is_array($val) ? wp_json_encode($val, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : (string)$val;
                printf('<textarea class="large-text code yse-mono" rows="8" name="%s[%s]" id="%s">%s</textarea>',
                    esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_textarea($display));
            } else {
                if (is_array($val)) $val = implode("\n",$val);
                printf('<textarea class="large-text" rows="6" name="%s[%s]" id="%s">%s</textarea>',
                    esc_attr(self::OPT_KEY), esc_attr($key), esc_attr($key), esc_textarea((string)$val));
            }

            if ($key==='identifier'){
                echo ' <a href="#" class="button-link" data-yse-example="identifier">Insert example</a>';
                echo '<div class="yse-help">JSON array, e.g. <code>[{"@type":"PropertyValue","propertyID":"DUNS","value":"123456789"}]</code></div>';
            }
            if ($key==='opening_hours'){
                echo ' <a href="#" class="button-link" data-yse-example="opening_hours">Insert example</a>';
                echo '<div class="yse-help">JSON array of <code>OpeningHoursSpecification</code>. Use 24-hour HH:MM. '
                . 'Tip: The example includes a weekend “closed” template (00:00–00:00). For maximum Google-compatibility, you can simply delete the weekend block so closed days are omitted.</div>';
            }
            if ($key==='entity_mentions'){
                echo ' <a href="#" class="button-link" data-yse-example="entity_mentions">Insert example</a>';
                echo '<div class="yse-help">JSON array of objects with <code>@id</code> URLs.</div>';
            }
            if ($key==='same_as'){
                echo '<div class="yse-help">One URL per line. Examples: <code>https://www.facebook.com/YourBrand</code>, <code>https://www.linkedin.com/company/yourbrand</code>, <code>https://g.co/kgs/...</code></div>';
            }
            if ($key==='service_area'){
                echo '<div class="yse-help">One city per line. Outputs structured <code>City</code> objects into <code>areaServed</code>.</div>';
            }
        } elseif ($type==='checkbox'){
            printf('<label><input type="checkbox" name="%s[%s]" value="1" %s/> Enable</label>',
                esc_attr(self::OPT_KEY), esc_attr($key), checked($val, '1', false));
        } elseif ($type==='select'){
            $is_dep = in_array($key, ['lb_subtype2','lb_subtype3'], true);
            $data_current = $is_dep ? ' data-current="'.esc_attr($val).'"' : '';
            echo '<select name="'.esc_attr(self::OPT_KEY).'['.esc_attr($key).']" id="'.esc_attr($key).'"'.$data_current.'>';
            if (!$is_dep){
                echo '<option value="">(Default)</option>';
                foreach ($options as $k=>$label){
                    printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
                }
            } else {
                echo '<option value="">(Optional)</option>';
            }
            echo '</select>';
        }

        if (!empty($help)){
            echo '<div class="yse-help">'.$help.'</div>';
        }
        echo '</div>';
    }

    public function render_faq_post_types_field(){
        $s = get_option(self::OPT_KEY, []);
        $enabled = isset($s['faq_post_types']) && is_array($s['faq_post_types']) ? $s['faq_post_types'] : [];
        $pts = get_post_types(['public'=>true, 'show_ui'=>true], 'objects');
        unset($pts['attachment']);
        echo '<div class="yse-field">';
        echo '<div class="yse-grid" style="grid-template-columns:repeat(3, 1fr);">';
        foreach ($pts as $slug => $obj){
            $checked = in_array($slug, $enabled, true) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" name="%s[faq_post_types][]" value="%s" %s/> %s</label>',
                esc_attr(self::OPT_KEY),
                esc_attr($slug),
                $checked,
                esc_html($obj->labels->name.' ('.$slug.')')
            );
        }
        echo '</div>';
        echo '<div class="yse-help">Nothing is selected by default. Toggle post types where editors should see the FAQ metabox.</div>';
        echo '</div>';
    }

    /* ------------- Multi-location UI ------------- */

    public function render_ml_repeater(){
        $s = get_option(self::OPT_KEY, []);
        $locs = (isset($s['ml_locations']) && is_array($s['ml_locations'])) ? $s['ml_locations'] : [];
        ?>
        <div id="yse-ml-wrap">
            <div class="yse-ml-head" style="display:flex;align-items:center;justify-content:space-between;">
                <p class="yse-help">Add each branch/office as a separate card. The <strong>Page Slug</strong> links a location page to its branch schema.</p>
                <p><a href="#" class="button" data-yse-act="add">Add location</a></p>
            </div>
            <div class="yse-ml-list">
                <?php foreach ($locs as $idx=>$L) { $this->render_ml_card($idx, $L); } ?>
            </div>

            <template>
                <?php $this->render_ml_card('__IDX__', []); ?>
            </template>
        </div>
        <?php
    }
    private function render_ml_card($idx, $L){
        $def = function($k, $d = '') use ($L) {
            return isset($L[$k]) ? $L[$k] : $d;
        };
        $p = 'yse_settings[ml_locations]['.esc_attr($idx).']';
        ?>
        <div class="yse-card yse-ml-item">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <h3 style="margin:0;">Location</h3>
                <a href="#" class="button button-link-delete" data-yse-act="remove">Remove</a>
            </div>

            <div class="yse-grid">
                <div class="yse-field">
                    <label>Name</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[name]" value="<?php echo esc_attr($def('name')); ?>" placeholder="e.g., Downtown Office"/>
                </div>
                <div class="yse-field">
                    <label>Page Slug</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[page_slug]" value="<?php echo esc_attr($def('page_slug')); ?>" placeholder="e.g., downtown"/>
                </div>
                <div class="yse-field">
                    <label>Public URL (optional)</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[url]" value="<?php echo esc_attr($def('url')); ?>" placeholder="https://example.com/locations/downtown"/>
                </div>
                <div class="yse-field">
                    <label>Image URL (optional)</label>
                    <?php $img_id = 'ml_img_'.esc_attr($idx); ?>
                    <input type="text" class="regular-text" id="<?php echo $img_id; ?>" name="<?php echo $p; ?>[image]" value="<?php echo esc_attr($def('image')); ?>" placeholder="https://.../photo.jpg"/>
                    <a href="#" class="button" data-yse-act="media" data-target="<?php echo $img_id; ?>">Select</a>
                </div>
            </div>

            <div class="yse-grid">
                <div class="yse-field">
                    <label>Telephone</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[telephone]" value="<?php echo esc_attr($def('telephone')); ?>" placeholder="+1-555-555-5555"/>
                    <div class="yse-help">Leave blank to <strong>inherit the global phone</strong>.</div>
                </div>
                <div class="yse-field">
                    <label>Email (optional)</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[email]" value="<?php echo esc_attr($def('email')); ?>" placeholder="branch@example.com"/>
                    <div class="yse-help">Leave blank to inherit the global email (or leave empty if you don’t publish an email).</div>
                </div>
                <div class="yse-field">
                    <label>Price Range (optional)</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[priceRange]" value="<?php echo esc_attr($def('priceRange')); ?>" placeholder="$$"/>
                </div>
            </div>

            <div class="yse-grid">
                <div class="yse-field">
                    <label>Street Address</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[addr_street]" value="<?php echo esc_attr($def('addr_street')); ?>"/>
                </div>
                <div class="yse-field">
                    <label>City</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[addr_city]" value="<?php echo esc_attr($def('addr_city')); ?>"/>
                </div>
                <div class="yse-field">
                    <label>Region/State</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[addr_region]" value="<?php echo esc_attr($def('addr_region')); ?>"/>
                </div>
                <div class="yse-field">
                    <label>Postal Code</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[addr_postal]" value="<?php echo esc_attr($def('addr_postal')); ?>"/>
                </div>
                <div class="yse-field">
                    <label>Country Code</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[addr_country]" value="<?php echo esc_attr($def('addr_country')); ?>" placeholder="US"/>
                </div>
            </div>

            <div class="yse-grid">
                <div class="yse-field">
                    <label>Latitude</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[geo_lat]" value="<?php echo esc_attr($def('geo_lat')); ?>" placeholder="38.5816"/>
                </div>
                <div class="yse-field">
                    <label>Longitude</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[geo_lng]" value="<?php echo esc_attr($def('geo_lng')); ?>" placeholder="-121.4944"/>
                </div>
            </div>

            <div class="yse-field">
                <label>Opening Hours (JSON array)</label>
                <textarea class="large-text code yse-mono" rows="6" name="<?php echo $p; ?>[opening_hours]"><?php
                    $oh = $def('opening_hours');
                    echo esc_textarea(is_array($oh) ? wp_json_encode($oh, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) : (string)$oh);
                ?></textarea>
                <a href="#" class="button-link" data-yse-act="hours-eg">Insert example</a>
                <div class="yse-help">Leave blank to <strong>inherit global hours</strong>. Example inserts Mon–Fri 09:00–17:00.</div>
            </div>

            <div class="yse-field">
                <label>Service Area – Cities (one per line)</label>
                <textarea class="large-text" rows="4" name="<?php echo $p; ?>[service_area]"><?php
                    $sa = $def('service_area');
                    echo esc_textarea(is_array($sa)?implode("\n",$sa):(string)$sa);
                ?></textarea>
            </div>

            <div class="yse-grid">
                <div class="yse-field">
                    <label>Subtype Tier 1 (optional)</label>
                    <select name="<?php echo $p; ?>[lb_subtype]" class="yse-lb1">
                        <option value="">(Inherit global)</option>
                        <?php foreach (self::local_subtypes() as $k=>$label): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($def('lb_subtype'), $k); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="yse-field">
                    <label>Subtype Tier 2 (optional)</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[lb_subtype2]" value="<?php echo esc_attr($def('lb_subtype2')); ?>" placeholder="e.g., AutoRepair"/>
                </div>
                <div class="yse-field">
                    <label>Subtype Tier 3 (optional)</label>
                    <input type="text" class="regular-text" name="<?php echo $p; ?>[lb_subtype3]" value="<?php echo esc_attr($def('lb_subtype3')); ?>" placeholder="e.g., MotorcycleDealer"/>
                </div>
            </div>
        </div>
        <?php
    }

    /* --------- Import/Export (no <hr/> before heading) --------- */

    private function render_import_export_panel(){
        if (!current_user_can('manage_options')) return;
        $settings = get_option(self::OPT_KEY, []);
        $export   = wp_json_encode($settings, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

        echo '<h2>Import / Export</h2>';
        echo '<div class="yse-grid-2">';

        echo '<div class="yse-card">';
        echo '<h3>Export</h3>';
        echo "<p>Copy this JSON and paste it into another site's Import box.</p>";
        echo '<textarea id="yse_export_json" class="large-text code yse-mono" rows="12" readonly>'.esc_textarea($export).'</textarea>';
        echo '<p><a href="#" id="yse-copy-export" class="button">Copy</a></p>';
        echo '</div>';

        echo '<div class="yse-card">';
        echo '<h3>Import</h3>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('yse_import_settings', 'yse_import_nonce');
        echo '<input type="hidden" name="action" value="yse_import"/>';
        echo '<p>Paste previously exported JSON here. This will replace current settings.</p>';
        echo '<textarea name="yse_import_json" class="large-text code yse-mono" rows="12"></textarea>';
        echo '<p><button class="button button-primary">Import Settings</button></p>';
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }

    /* --------- Settings page rendering (card starts after heading) --------- */

    public function render_settings_page(){
        $yoast_ok     = $this->yoast_available();
        $yoast_local  = $this->yoast_local_available();
        $status_table = $this->gather_org_status_rows();
        ?>
        <div class="wrap">
            <h1>Schema Extender</h1>
            <?php settings_errors(self::OPT_KEY); ?>
            <?php if(isset($_GET['yse_import'])): ?>
                <?php if($_GET['yse_import']==='ok'): ?>
                    <div class="notice notice-success"><p>Import completed.</p></div>
                <?php elseif($_GET['yse_import']==='fail'): ?>
                    <div class="notice notice-error"><p>Import failed: invalid JSON.</p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if(!$yoast_ok): ?>
                <div class="notice notice-warning"><p>Yoast SEO not detected. The schema extensions will be inactive until Yoast SEO is active.</p></div>
            <?php endif; ?>
            <?php if($yoast_local): ?>
                <div class="notice notice-info"><p>Yoast Local SEO detected. Our LocalBusiness enrichment coexists but we avoid duplication where possible. Validate output if both are active.</p></div>
            <?php endif; ?>

            <h2>Current Site Representation Status</h2>
            <div class="yse-status">
                <table>
                    <thead><tr><th>Field</th><th>Yoast Value</th><th>Extender Value</th><th>Effective</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php foreach ($status_table as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['field']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['yoast']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['ext']); ?></td>
                            <td class="yse-mono"><?php echo esc_html($row['effective']); ?></td>
                            <td><?php echo $row['source'] === 'yoast' ? '<span class="yse-good">Yoast</span>' : ($row['source']==='ext' ? '<span class="yse-warn">Extender</span>' : 'Merged'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="yse-help">“Source” shows where the effective value is coming from (respecting your override setting).</p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPT_KEY);

                // --- Heading OUTSIDE the card ---
                $this->render_section_heading('yse_main');

                // --- Card starts AT the paragraph (section callback) and includes all fields + rest of sections ---
                echo '<div class="yse-card yse-form-card" style="margin-top:16px;">';

                    // yse_main body (paragraph + table of fields)
                    $this->render_section_body('yse_main');

                    // Render the remaining sections (their headings + bodies) inside this same card
                    $this->render_sections(['yse_ml','yse_intent','yse_cpt','yse_mentions','yse_faq','yse_overrides']);

                    submit_button('Save Settings');

                echo '</div>';
                ?>
            </form>

            <?php $this->render_import_export_panel(); ?>

            <p class="yse-help">Validate with Google Rich Results Test and validator.schema.org.</p>
        </div>
        <?php
    }

    /**
     * Render specific Settings API sections by ID, preserving WP's native markup.
     * Helper to print only the <h2> title of a section.
     */
    private function render_section_heading($id){
        global $wp_settings_sections;
        $page = 'yse-settings';
        if (!empty($wp_settings_sections[$page][$id]['title'])){
            echo '<h2>'.esc_html($wp_settings_sections[$page][$id]['title']).'</h2>';
        }
    }

    /**
     * Helper to print only the body (callback + fields) of a section, without the <h2>.
     */
    private function render_section_body($id){
        global $wp_settings_sections, $wp_settings_fields;
        $page = 'yse-settings';
        if (!empty($wp_settings_sections[$page][$id]['callback']) && is_callable($wp_settings_sections[$page][$id]['callback'])){
            call_user_func($wp_settings_sections[$page][$id]['callback'], $wp_settings_sections[$page][$id]);
        }
        if (!empty($wp_settings_fields[$page][$id])){
            echo '<table class="form-table" role="presentation">';
            do_settings_fields($page, $id);
            echo '</table>';
        }
    }

    /**
     * Render multiple sections (title + body).
     */
    private function render_sections(array $ids){
        global $wp_settings_sections, $wp_settings_fields;
        $page = 'yse-settings';
        foreach ($ids as $id){
            if (empty($wp_settings_sections[$page][$id])) continue;
            $section = $wp_settings_sections[$page][$id];

            if ($section['title']){
                echo '<h2>'.esc_html($section['title']).'</h2>';
            }
            if (!empty($section['callback']) && is_callable($section['callback'])){
                call_user_func($section['callback'], $section);
            }
            if (!empty($wp_settings_fields[$page][$id])){
                echo '<table class="form-table" role="presentation">';
                do_settings_fields($page, $id);
                echo '</table>';
            }
        }
    }

    private function gather_org_status_rows(){
        $yoast_name = get_bloginfo('name');
        $yoast_url  = home_url('/');
        $yoast_logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';
        $yoast_email = '';

        $s = get_option(self::OPT_KEY, []);
        $override = !empty($s['override_org']) && $s['override_org']==='1';

        $ext_name  = $s['org_name'] ?? '';
        $ext_url   = $s['org_url'] ?? '';
        $ext_logo  = $s['org_logo'] ?? '';
        $ext_email = $s['org_email'] ?? '';
        $ext_tel   = $s['telephone'] ?? '';

        $eff_name = $override ? ($ext_name ?: $yoast_name) : ($yoast_name ?: $ext_name);
        $eff_url  = $override ? ($ext_url  ?: $yoast_url ) : ($yoast_url  ?: $ext_url );
        $eff_logo = $override ? ($ext_logo ?: $yoast_logo) : ($yoast_logo ?: $ext_logo);
        $eff_email= $override ? ($ext_email?: $yoast_email): ($yoast_email?: $ext_email);
        $eff_tel  = $ext_tel;

        $rows = [];
        $rows[] = ['field'=>'name','yoast'=>$yoast_name,'ext'=>$ext_name,'effective'=>$eff_name,'source'=> ($override ? ( $ext_name ? 'ext':'yoast') : ( $yoast_name ? 'yoast':'ext'))];
        $rows[] = ['field'=>'url','yoast'=>$yoast_url,'ext'=>$ext_url,'effective'=>$eff_url,'source'=> ($override ? ( $ext_url ? 'ext':'yoast') : ( $yoast_url ? 'yoast':'ext'))];
        $rows[] = ['field'=>'logo','yoast'=>$yoast_logo,'ext'=>$ext_logo,'effective'=>$eff_logo,'source'=> ($override ? ( $ext_logo ? 'ext':'yoast') : ( $yoast_logo ? 'yoast':'ext'))];
        $rows[] = ['field'=>'email','yoast'=>$yoast_email,'ext'=>$ext_email,'effective'=>$eff_email,'source'=> ($override ? ( $ext_email ? 'ext':'yoast') : ( $yoast_email ? 'yoast':'ext'))];
        $rows[] = ['field'=>'telephone','yoast'=>'','ext'=>$eff_tel,'effective'=>$eff_tel,'source'=> 'ext'];

        return $rows;
    }
    
    /**
     * Convert stored <br> tags back to real newlines for textarea display.
     * This is only for the FAQ answer UI; schema keeps the <br> version.
     */
    private function yse_faq_br_to_newlines($s){
        if (!is_string($s) || $s === '') return $s;

        // Normalize any stray CRLF / CR into LF first.
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // Convert <br>, <br/>, <br /> (any case) into LF.
        $s = preg_replace('/<br\\s*\\/?\\s*>/i', "\n", $s);

        return $s;
    }

    /* ------------- Sanitization helpers ------------- */

    public function sanitize_settings($in){
        $out = [];
        $out['org_name']  = sanitize_text_field($in['org_name'] ?? '');
        $out['org_url']   = esc_url_raw($in['org_url'] ?? '');
        $out['org_logo']  = esc_url_raw($in['org_logo'] ?? '');
        $out['org_email'] = sanitize_email($in['org_email'] ?? '');
        $out['telephone'] = sanitize_text_field($in['telephone'] ?? '');

        // one URL per line → array of URLs
        $out['same_as']      = $this->sanitize_lines_as_urls($in['same_as'] ?? '');
        // one city per line → array of strings
        $out['service_area'] = $this->sanitize_lines_as_text($in['service_area'] ?? '');

        $out['identifier']      = $this->sanitize_json_field($in['identifier'] ?? '[]', [], 'identifier', 'Identifiers');
        $out['opening_hours']   = $this->sanitize_json_field($in['opening_hours'] ?? '[]', [], 'opening_hours', 'Opening Hours');
        $out['entity_mentions'] = $this->sanitize_json_field($in['entity_mentions'] ?? '[]', [], 'entity_mentions', 'Topic Mentions');

        $out['is_local']  = !empty($in['is_local']) ? '1' : '0';
        $out['lb_subtype']  = preg_replace('/[^A-Za-z]/','', sanitize_text_field($in['lb_subtype'] ?? ''));
        $out['lb_subtype2'] = preg_replace('/[^A-Za-z]/','', sanitize_text_field($in['lb_subtype2'] ?? ''));
        $out['lb_subtype3'] = preg_replace('/[^A-Za-z]/','', sanitize_text_field($in['lb_subtype3'] ?? ''));

        $out['addr_street']  = sanitize_text_field($in['addr_street'] ?? '');
        $out['addr_city']    = sanitize_text_field($in['addr_city'] ?? '');
        $out['addr_region']  = sanitize_text_field($in['addr_region'] ?? '');
        $out['addr_postal']  = sanitize_text_field($in['addr_postal'] ?? '');
        $out['addr_country'] = sanitize_text_field($in['addr_country'] ?? '');
        $out['geo_lat']      = sanitize_text_field($in['geo_lat'] ?? '');
        $out['geo_lng']      = sanitize_text_field($in['geo_lng'] ?? '');

        $out['slug_about']     = sanitize_title($in['slug_about'] ?? '');
        $out['slug_contact']   = sanitize_title($in['slug_contact'] ?? '');
        $out['faq_shortcode']  = sanitize_key($in['faq_shortcode'] ?? '');
        $out['howto_shortcode']= sanitize_key($in['howto_shortcode'] ?? '');
        $out['extra_faq_slug'] = sanitize_title($in['extra_faq_slug'] ?? '');

        $out['cpt_map'] = $this->sanitize_cpt_map($in['cpt_map'] ?? '');

        $out['override_org'] = !empty($in['override_org']) ? '1' : '0';

        $out['ml_enabled'] = !empty($in['ml_enabled']) ? '1' : '0';
        $out['ml_locations'] = [];
        if (!empty($in['ml_locations']) && is_array($in['ml_locations'])){
            foreach ($in['ml_locations'] as $loc){
                $clean = [];
                $clean['name']        = sanitize_text_field($loc['name'] ?? '');
                $clean['page_slug']   = sanitize_title($loc['page_slug'] ?? '');
                $clean['url']         = esc_url_raw($loc['url'] ?? '');
                $clean['image']       = esc_url_raw($loc['image'] ?? '');
                $clean['telephone']   = sanitize_text_field($loc['telephone'] ?? '');
                $clean['email']       = sanitize_email($loc['email'] ?? '');
                $clean['priceRange']  = sanitize_text_field($loc['priceRange'] ?? '');
                $clean['addr_street'] = sanitize_text_field($loc['addr_street'] ?? '');
                $clean['addr_city']   = sanitize_text_field($loc['addr_city'] ?? '');
                $clean['addr_region'] = sanitize_text_field($loc['addr_region'] ?? '');
                $clean['addr_postal'] = sanitize_text_field($loc['addr_postal'] ?? '');
                $clean['addr_country']= sanitize_text_field($loc['addr_country'] ?? '');
                $clean['geo_lat']     = sanitize_text_field($loc['geo_lat'] ?? '');
                $clean['geo_lng']     = sanitize_text_field($loc['geo_lng'] ?? '');
                $clean['opening_hours'] = $this->sanitize_json_field($loc['opening_hours'] ?? '[]', [], 'ml_opening_hours', 'Location Opening Hours');
                $clean['service_area']  = $this->sanitize_lines_as_text($loc['service_area'] ?? '');

                $clean['lb_subtype']  = preg_replace('/[^A-Za-z]/','', sanitize_text_field($loc['lb_subtype'] ?? ''));
                $clean['lb_subtype2'] = preg_replace('/[^A-Za-z]/','', sanitize_text_field($loc['lb_subtype2'] ?? ''));
                $clean['lb_subtype3'] = preg_replace('/[^A-Za-z]/','', sanitize_text_field($loc['lb_subtype3'] ?? ''));

                if ($clean['name'] || $clean['addr_street'] || $clean['page_slug']){
                    $out['ml_locations'][] = $clean;
                }
            }
        }

        // FAQ Builder enabled post types
        $out['faq_post_types'] = [];
        if (!empty($in['faq_post_types']) && is_array($in['faq_post_types'])){
            $pts = get_post_types(['public'=>true,'show_ui'=>true],'names');
            unset($pts['attachment']);
            foreach ($in['faq_post_types'] as $slug){
                $slug = sanitize_key($slug);
                if (in_array($slug, $pts, true)) $out['faq_post_types'][] = $slug;
            }
        }

        return $out;
    }

    private function sanitize_lines_as_urls($raw){
        // If already an array (from previous saves), normalize and return.
        if (is_array($raw)) {
            $urls = [];
            foreach ($raw as $v) {
                if (!is_string($v)) {
                    continue;
                }
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
                $u = esc_url_raw($v);
                if ($u) {
                    $urls[] = $u;
                }
            }
            return $urls;
        }

        // Normal textarea case: one URL per line.
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $lines = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw))
        );

        $urls = [];
        foreach ($lines as $l) {
            $u = esc_url_raw($l);
            if ($u) {
                $urls[] = $u;
            }
        }

        return $urls;
    }

    private function sanitize_lines_as_text($raw){
        // If already an array, clean each entry and return.
        if (is_array($raw)) {
            $safe = [];
            foreach ($raw as $v) {
                if (!is_string($v)) {
                    continue;
                }
                $v = trim(wp_strip_all_tags($v));
                if ($v !== '') {
                    $safe[] = $v;
                }
            }
            return array_values(array_unique($safe));
        }

        // Normal textarea case.
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $lines = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw))
        );

        $safe = [];
        foreach ($lines as $l) {
            $l = wp_strip_all_tags($l);
            if ($l !== '') {
                $safe[] = $l;
            }
        }

        return array_values(array_unique($safe));
    }

    private function sanitize_json_field($raw, $fallback, $field_key, $human_label){
        // If already an array from previous saves, trust it and return.
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $raw = wp_unslash($raw);
        } else {
            $raw = '';
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw_trim = trim($raw);
        if ($raw_trim === '') {
            return $fallback;
        }

        $decoded = json_decode($raw_trim, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $attempt = $raw_trim;
            if (preg_match('/^\s*\{.*\}\s*$/s', $attempt)) {
                $attempt = '['.$attempt.']';
            }
            $attempt = preg_replace('/,\s*(\]|\})/m', '$1', $attempt);
            $decoded = json_decode($attempt, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return is_array($decoded) ? $decoded : $fallback;
            }

            $msg = json_last_error_msg();
            $hints = [
                'Check for missing commas between items.',
                'Use straight double-quotes (").',
                'Remove trailing commas.',
                'Ensure it is a JSON array: [ {...}, {...} ].',
            ];
            add_settings_error(
                self::OPT_KEY,
                "json_error_{$field_key}",
                sprintf(
                    '%s: Invalid JSON. %s Hints: %s',
                    esc_html($human_label),
                    esc_html($msg),
                    esc_html(implode(' ', $hints))
                ),
                'error'
            );
            return $fallback;
        }

        if (!is_array($decoded)) {
            add_settings_error(
                self::OPT_KEY,
                "json_type_{$field_key}",
                sprintf('%s must be a JSON array (e.g. [ ... ]).', esc_html($human_label)),
                'error'
            );
            return $fallback;
        }

        return $decoded;
    }

    private function sanitize_cpt_map($raw){
        // If already an array, normalize keys/values and return.
        if (is_array($raw)) {
            $map = [];
            foreach ($raw as $cpt => $type) {
                $cpt = sanitize_key($cpt);
                $type = preg_replace(
                    '/[^A-Za-z]/',
                    '',
                    is_string($type) ? $type : (string)$type
                );
                if ($cpt && $type) {
                    $map[$cpt] = $type;
                }
            }
            return $map;
        }

        // Normal textarea case: one "cpt:Type" per line.
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $lines = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw))
        );

        $map = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$cpt, $type] = array_map('trim', explode(':', $line, 2));
                if ($cpt && $type) {
                    $map[sanitize_key($cpt)] = preg_replace('/[^A-Za-z]/', '', $type);
                }
            }
        }

        return $map;
    }

    private static function local_subtypes(){
        return [
            'LocalBusiness'              => 'LocalBusiness',
            'ProfessionalService'        => 'ProfessionalService',
            'MedicalOrganization'        => 'MedicalOrganization',
            'HealthAndBeautyBusiness'    => 'HealthAndBeautyBusiness',
            'HomeAndConstructionBusiness'=> 'HomeAndConstructionBusiness',
            'AutomotiveBusiness'         => 'AutomotiveBusiness',
            'FoodEstablishment'          => 'FoodEstablishment',
            'LodgingBusiness'            => 'LodgingBusiness',
            'Store'                      => 'Store',
        ];
    }

    /* ------------- Admin list columns (FAQ only) ------------- */

    public function register_admin_columns(){
        $post_types = get_post_types(['public'=>true],'names');
        foreach ($post_types as $pt){
            add_filter("manage_{$pt}_posts_columns", function($cols){
                $cols['yse_faq_count'] = 'FAQs';
                return $cols;
            });
            add_action("manage_{$pt}_posts_custom_column", function($col, $post_id){
                if ($col === 'yse_faq_count'){
                    $enabled = get_post_meta($post_id, '_yse_faq_enabled', true) === '1';
                    $items = get_post_meta($post_id, '_yse_faq_items', true);
                    $arr = [];
                    if ($items){
                        $arr = json_decode($items, true);
                        if (json_last_error() !== JSON_ERROR_NONE) $arr = [];
                    }
                    echo $enabled && !empty($arr) ? count($arr) : '—';
                }
            }, 10, 2);
        }
    }

    /* ------------- FAQ Builder Metabox ------------- */

    public function add_faq_metaboxes(){
        $s = get_option(self::OPT_KEY, []);
        $enabled_pts = isset($s['faq_post_types']) && is_array($s['faq_post_types']) ? $s['faq_post_types'] : [];
        if (empty($enabled_pts)) return;
        foreach ($enabled_pts as $pt){
            add_meta_box('yse_faq_builder','FAQ (Questions & Answers)',$this->callback_guard([$this,'render_faq_metabox']),$pt,'normal','default');
        }
    }

    private function callback_guard($fn){
        return function() use ($fn){ call_user_func_array($fn, func_get_args()); };
    }

    public function render_faq_metabox($post){
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field('yse_faq_save', 'yse_faq_nonce');

        $enabled = get_post_meta($post->ID, '_yse_faq_enabled', true) === '1';
        $raw = get_post_meta($post->ID, '_yse_faq_items', true);
        $items = [];
        if ($raw){
            $dec = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                foreach ($dec as &$qa){
                    if (isset($qa['a']) && is_string($qa['a'])) {
                        // Stored schema text uses <br>. Convert back to real newlines for the textarea.
                        $qa['a'] = $this->yse_faq_br_to_newlines($qa['a']);
                    }
                }
                unset($qa);
                $items = $dec;
            }
        }

        echo '<div class="yse-faq-wrap">';
        echo '<p><label><input type="checkbox" name="yse_faq_enabled" value="1" '.checked($enabled,true,false).'/> Enable FAQ for this post</label></p>';
        echo '<p class="yse-help">Guidelines:</p>';
        echo '<ul class="yse-list">';
        echo '<li>&bull; Aim for concise, self-contained answers: <strong>1–3 sentences</strong>, roughly <strong>25–75 words</strong>.</li>';
        echo '<li>&bull; Hard cap: keep it under <strong>150 words</strong>. Long rambles tank scannability and reduce chances of a nice rich result.</li>';
        echo '<li>&bull; Pattern that works: <strong>direct answer first</strong>, then one clarifying detail. If it needs more, your answer is probably a mini-article—link to it instead of stuffing it here.</li>';
        echo '</ul>';

        echo '<div class="yse-faq-list">';
        if (!empty($items)){
            foreach ($items as $idx=>$qa){
                $q = isset($qa['q']) ? $qa['q'] : '';
                $a = isset($qa['a']) ? $qa['a'] : '';
                $this->render_faq_row($idx, $q, $a);
            }
        }
        echo '</div>';
        echo '<p><a href="#" class="button" data-yse-faq-add>Add Q&A</a></p>';

        echo '<template>';
        $this->render_faq_row('__IDX__', '', '');
        echo '</template>';

        echo '<div class="yse-help">Allowed in <strong>Answer</strong>: basic formatting (paragraphs, lists, links). We automatically clean unsafe HTML. Max 20 Q&As.</div>';
        echo '</div>';
    }

    private function render_faq_row($idx, $q, $a){
        $q = is_string($q) ? $q : '';
        $a = is_string($a) ? $a : '';
        ?>
        <div class="yse-faq-item">
            <div class="yse-row">
                <label>Question</label>
                <input type="text" name="yse_faq_q[<?php echo esc_attr($idx); ?>]" value="<?php echo esc_attr($q); ?>" placeholder="e.g., How long does a typical project take?"/>
            </div>
            <div class="yse-row">
                <label>Answer</label>
                <textarea name="yse_faq_a[<?php echo esc_attr($idx); ?>]" placeholder="Short, helpful answer. Avoid pure marketing language."><?php echo esc_textarea($a); ?></textarea>
            </div>
            <div class="yse-faq-actions">
                <a href="#" class="button-link" data-yse-faq-up>Move up</a>
                <a href="#" class="button-link" data-yse-faq-dn>Move down</a>
                <a href="#" class="button-link" data-yse-faq-del>Remove</a>
            </div>
        </div>
        <?php
    }

    public function save_faq_meta($post_id, $post){
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['yse_faq_nonce']) || !wp_verify_nonce($_POST['yse_faq_nonce'], 'yse_faq_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $enabled = isset($_POST['yse_faq_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_yse_faq_enabled', $enabled);

        $qs = isset($_POST['yse_faq_q']) && is_array($_POST['yse_faq_q']) ? $_POST['yse_faq_q'] : [];
        $as = isset($_POST['yse_faq_a']) && is_array($_POST['yse_faq_a']) ? $_POST['yse_faq_a'] : [];

        $pairs = [];
        foreach ($qs as $k=>$q){
            $q = is_string($q) ? $q : '';
            $q = trim(wp_strip_all_tags($q));

            $a = isset($as[$k]) ? (string) wp_unslash($as[$k]) : '';

            // 1) Normalize OS newlines to LF, 2) convert LF → <br> for schema storage.
            // This keeps the JSON compact while still giving Google visible line breaks.
            $a = str_replace(["\r\n", "\r"], "\n", $a);
            $a = str_replace("\n", '<br>', $a);

            $a = wp_kses($a, [
                'p'=>[], 'br'=>[], 'strong'=>[], 'em'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[],
                'a'=>['href'=>[], 'title'=>[], 'rel'=>[], 'target'=>[]],
            ]);
            if ($q !== '' && $a !== ''){
                if (!preg_match('/\?\s*$/u', $q)) $q .= '?';
                $pairs[] = ['q'=>$q, 'a'=>$a];
            }
            if (count($pairs) >= 20) break;
        }

        if (!empty($pairs)){
            update_post_meta(
                $post_id,
                '_yse_faq_items',
                wp_json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        } else {
            delete_post_meta($post_id, '_yse_faq_items');
        }
    }

    /* ------------- Yoast filters / pieces ------------- */

    private function hook_schema_filters(){

        // Merge & enrich Organization with LocalBusiness facets
        add_filter('wpseo_schema_organization', function($data){
            $s = get_option(self::OPT_KEY, []);
            $types = [];
            if (isset($data['@type'])) $types = is_array($data['@type']) ? $data['@type'] : [$data['@type']];
            if (empty($types)) $types = ['Organization'];

            $override = !empty($s['override_org']) && $s['override_org'] === '1';
            $is_local = !empty($s['is_local']) && $s['is_local'] === '1';

            if ($is_local) {
                $types[] = 'LocalBusiness';
                foreach (['lb_subtype','lb_subtype2','lb_subtype3'] as $k) {
                    if (!empty($s[$k])) $types[] = preg_replace('/[^A-Za-z]/','', $s[$k]);
                }
            }

            $types = array_values(array_unique(array_filter($types)));
            $data['@type'] = (count($types) === 1) ? $types[0] : $types;

            if (empty($data['@id'])) $data['@id'] = home_url('#/schema/organization');

            if ($override || empty($data['name'])) $data['name'] = !empty($s['org_name']) ? $s['org_name'] : ($data['name'] ?? get_bloginfo('name'));
            if ($override || empty($data['url']))  $data['url']  = !empty($s['org_url'])  ? $s['org_url']  : ($data['url']  ?? home_url('/'));
            if (!empty($s['org_logo']) && ($override || empty($data['logo']))) $data['logo'] = ['@type'=>'ImageObject','url'=>$s['org_logo']];
            if (!empty($s['org_email']) && ($override || empty($data['email']))) $data['email'] = $s['org_email'];
            if (!empty($s['telephone']) && ($override || empty($data['telephone']))) $data['telephone'] = $s['telephone'];

            $existing_sameas = isset($data['sameAs']) && is_array($data['sameAs']) ? $data['sameAs'] : [];
            $ours_sameas     = !empty($s['same_as']) ? (array)$s['same_as'] : [];
            $data['sameAs']  = array_values(array_unique(array_filter(array_merge($existing_sameas, $ours_sameas))));

            if (!empty($s['identifier']) && is_array($s['identifier'])) {
                $data['identifier'] = array_values(array_merge($data['identifier'] ?? [], $s['identifier']));
            }

            $addr = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $s['addr_street'] ?? '',
                'addressLocality' => $s['addr_city'] ?? '',
                'addressRegion'   => $s['addr_region'] ?? '',
                'postalCode'      => $s['addr_postal'] ?? '',
                'addressCountry'  => $s['addr_country'] ?? '',
            ]);
            if (!empty($addr['streetAddress'])) {
                if ($override || empty($data['address'])) $data['address'] = $addr;
            }
            if (!empty($s['opening_hours']) && is_array($s['opening_hours']) && !empty($s['opening_hours'])) {
                if ($override || empty($data['openingHoursSpecification'])) $data['openingHoursSpecification'] = $s['opening_hours'];
            }
            if (!empty($s['geo_lat']) && !empty($s['geo_lng'])) {
                if ($override || empty($data['geo'])) {
                    $data['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$s['geo_lat'],'longitude'=>$s['geo_lng']];
                }
            }

            $sareas = !empty($s['service_area']) && is_array($s['service_area']) ? $s['service_area'] : [];
            if (!empty($sareas)){
                $cities = [];
                foreach ($sareas as $name) {
                    $name = wp_strip_all_tags($name);
                    if ($name!=='') $cities[] = [ '@type' => 'City', 'name' => $name ];
                }
                if (!empty($cities)){
                    $existing = isset($data['areaServed']) ? (array) $data['areaServed'] : [];
                    $merged   = array_merge($existing, $cities);
                    $seen = []; $deduped = [];
                    foreach ($merged as $it) {
                        $key = is_array($it) && isset($it['name']) ? 'city:'.strtolower($it['name']) : md5(maybe_serialize($it));
                        if (!isset($seen[$key])) { $seen[$key] = true; $deduped[] = $it; }
                    }
                    $data['areaServed'] = $deduped;
                }
            }

            return $data;
        }, 99);

        // WebPage typing from SETTINGS ONLY (no per-post override)
        add_filter('wpseo_schema_webpage', function($data){
            if (!is_singular()) return $data;
            $s = get_option(self::OPT_KEY, []);
            global $post;
            $slug = $post ? $post->post_name : '';
            $content = $post ? get_post_field('post_content', $post) : '';

            if (!empty($s['slug_about']) && $slug === $s['slug_about']){
                $data['@type'] = 'AboutPage';
            } elseif (!empty($s['slug_contact']) && $slug === $s['slug_contact']){
                $data['@type'] = 'ContactPage';
            } elseif (!empty($s['extra_faq_slug']) && $slug === $s['extra_faq_slug']){
                $data['@type'] = 'FAQPage';
            } else {
                if (!empty($s['faq_shortcode']) && function_exists('has_shortcode') && has_shortcode($content, $s['faq_shortcode'])){
                    $data['@type'] = 'FAQPage';
                } elseif (!empty($s['howto_shortcode']) && function_exists('has_shortcode') && has_shortcode($content, $s['howto_shortcode'])){
                    $data['@type'] = 'HowTo';
                }
            }

            $global_mentions = (!empty($s['entity_mentions']) && is_array($s['entity_mentions'])) ? $s['entity_mentions'] : [];
            if (!empty($global_mentions)){
                $existing_about = (isset($data['about']) && is_array($data['about'])) ? $data['about'] : [];
                $incoming = array_merge($existing_about, $global_mentions);
                $seen=[]; $merged=[];
                foreach ($incoming as $it){
                    if (is_array($it) && isset($it['@id'])){
                        $key = 'id:'.strtolower(trim($it['@id']));
                    } else {
                        $key = 'txt:'.strtolower(trim(is_string($it)?$it:wp_json_encode($it)));
                    }
                    if (!isset($seen[$key])){ $seen[$key]=true; $merged[] = $it; }
                }
                $data['about']    = $merged;
                $data['mentions'] = $merged;
            }

            return $data;
        }, 20);

        // CPT mapping piece, Breadcrumb, Video, Multi-Location
        add_filter('wpseo_schema_graph_pieces', function($pieces, $context){
            if (!is_singular()) return $pieces;
            $s = get_option(self::OPT_KEY, []);
            global $post;

            // CPT → Type mapping (settings only)
            $map   = is_array($s['cpt_map'] ?? null) ? $s['cpt_map'] : [];
            $ptype = $post ? get_post_type($post) : '';
            $type  = ($ptype && isset($map[$ptype])) ? preg_replace('/[^A-Za-z]/','', $map[$ptype]) : '';

            if ($type && class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece')){
                $pieces[] = new class($context, $type, $post) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                    private $type; private $post;
                    public function __construct($context, $type, $post){ parent::__construct($context); $this->type=$type; $this->post=$post; }
                    public function is_needed(){ return true; }
                    public function generate(){
                        $id = get_permalink($this->post).'#/schema/'.strtolower($this->type);
                        $org_id = home_url('#/schema/organization');
                        $desc_raw = get_the_excerpt($this->post);
                        if (!$desc_raw) $desc_raw = get_post_field('post_content', $this->post);
                        $desc = $desc_raw ? wp_strip_all_tags($desc_raw) : '';
                        $graph = [
                            '@type'       => $this->type,
                            '@id'         => $id,
                            'name'        => get_the_title($this->post),
                            'url'         => get_permalink($this->post),
                            'description' => $desc ? wp_trim_words($desc, 60, '') : '',
                            'provider'    => ['@id'=>$org_id],
                        ];
                        return $graph;
                    }
                };
            }

            // BreadcrumbList (simple, consistent)
            if (class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece')){
                $pieces[] = new class($context) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                    public function is_needed(){ return true; }
                    public function generate(){
                        $crumbs = [];
                        $pos = 1;
                        $crumbs[] = ['@type'=>'ListItem','position'=>$pos++,'item'=>['@id'=>home_url('/'),'name'=>get_bloginfo('name')]];
                        if (is_singular()){
                            $crumbs[] = ['@type'=>'ListItem','position'=>$pos++,'item'=>['@id'=>get_permalink(),'name'=>get_the_title()]];
                        }
                        return ['@type'=>'BreadcrumbList','@id'=>get_permalink().'#/schema/breadcrumb','itemListElement'=>$crumbs];
                    }
                };
            }

            // Detect iframes (YouTube/Vimeo) → lightweight VideoObject
            $content_full = $post ? apply_filters('the_content', $post->post_content) : '';
            if ($content_full && preg_match('/<iframe[^>]+src="[^"]*(youtube|vimeo)\.com[^"]+"/i', $content_full) && class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece')){
                $pieces[] = new class($context) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                    public function is_needed(){ return true; }
                    public function generate(){
                        return [
                            '@type'=>'VideoObject',
                            '@id'=>get_permalink().'#/schema/video',
                            'name'=>get_the_title(),
                            'description'=>wp_strip_all_tags(get_the_excerpt() ?: ''),
                            'thumbnailUrl'=>[],
                            'uploadDate'=>get_post_time('c', true),
                            'url'=>get_permalink(),
                        ];
                    }
                };
            }

            // Multi-location branch nodes
            $s_global = get_option('yse_settings', []);
            if (!empty($s_global['ml_enabled']) && $s_global['ml_enabled']==='1' && !empty($s_global['ml_locations']) && class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece')){
                foreach ($s_global['ml_locations'] as $loc){
                    $pieces[] = new class($context, $loc, $s_global) extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {
                        private $loc; private $cfg;
                        public function __construct($context, $loc, $cfg){ parent::__construct($context); $this->loc=$loc; $this->cfg=$cfg; }
                        public function is_needed(){ return true; }
                        public function generate(){
                            $slug = $this->loc['page_slug'] ? sanitize_title($this->loc['page_slug']) : sanitize_title($this->loc['name']);
                            $id   = home_url('#/schema/location/'.$slug);
                            $org_id = home_url('#/schema/organization');

                            $types = ['LocalBusiness'];
                            foreach (['lb_subtype','lb_subtype2','lb_subtype3'] as $k){
                                $v = $this->loc[$k] ?? '';
                                if (!$v && isset($this->cfg[$k])) $v = $this->cfg[$k];
                                if ($v) $types[] = preg_replace('/[^A-Za-z]/','', $v);
                            }
                            $types = array_values(array_unique(array_filter($types)));

                            $addr = array_filter([
                                '@type'           => 'PostalAddress',
                                'streetAddress'   => $this->loc['addr_street'] ?? '',
                                'addressLocality' => $this->loc['addr_city'] ?? '',
                                'addressRegion'   => $this->loc['addr_region'] ?? '',
                                'postalCode'      => $this->loc['addr_postal'] ?? '',
                                'addressCountry'  => $this->loc['addr_country'] ?? '',
                            ]);

                            $hours = [];
                            if (!empty($this->loc['opening_hours']) && is_array($this->loc['opening_hours']) && !empty($this->loc['opening_hours'])){
                                $hours = $this->loc['opening_hours'];
                            } elseif (!empty($this->cfg['opening_hours']) && is_array($this->cfg['opening_hours']) && !empty($this->cfg['opening_hours'])){
                                $hours = $this->cfg['opening_hours'];
                            }

                            $phone = !empty($this->loc['telephone']) ? $this->loc['telephone'] : ($this->cfg['telephone'] ?? '');
                            $email = !empty($this->loc['email'])     ? $this->loc['email']     : ($this->cfg['org_email'] ?? '');

                            $g = [
                                '@type' => (count($types)===1 ? $types[0] : $types),
                                '@id'   => $id,
                                'name'  => $this->loc['name'] ?: get_bloginfo('name'),
                                'url'   => !empty($this->loc['url']) ? $this->loc['url'] : (home_url('/')),
                                'parentOrganization' => [ '@id' => $org_id ],
                            ];

                            if (!empty($this->loc['image']))     $g['image'] = [ '@type'=>'ImageObject', 'url'=>$this->loc['image'] ];
                            if (!empty($phone))                  $g['telephone'] = $phone;
                            if (!empty($email))                  $g['email'] = $email;
                            if (!empty($this->loc['priceRange']))$g['priceRange'] = $this->loc['priceRange'];
                            if (!empty($addr['streetAddress']))  $g['address'] = $addr;
                            if (!empty($this->loc['geo_lat']) && !empty($this->loc['geo_lng'])){
                                $g['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$this->loc['geo_lat'],'longitude'=>$this->loc['geo_lng']];
                            }
                            if (!empty($hours)) {
                                $g['openingHoursSpecification'] = $hours;
                            }

                            if (!empty($this->loc['service_area']) && is_array($this->loc['service_area'])){
                                $cities=[];
                                foreach ($this->loc['service_area'] as $n){
                                    $n = wp_strip_all_tags($n);
                                    if ($n!=='') $cities[] = ['@type'=>'City','name'=>$n];
                                }
                                if (!empty($cities)) $g['areaServed'] = $cities;
                            }

                            return $g;
                        }
                    };
                }
            }

            return $pieces;
        }, 20, 2);

        add_filter('wpseo_schema_article', function($data){
            $data['isPartOf'] = $data['isPartOf'] ?? ['@id' => get_permalink().'#/schema/webpage'];
            $data['publisher'] = ['@id' => home_url('#/schema/organization')];
            return $data;
        }, 20);

        // Warning if LocalBusiness enabled but no address
        add_action('admin_notices', function(){
            if (!current_user_can('manage_options')) return;
            $s = get_option(self::OPT_KEY, []);
            if (!empty($s['is_local']) && $s['is_local']==='1'){
                $addr_ok = !empty($s['addr_street']);
                if (!$addr_ok){
                    echo '<div class="notice notice-warning"><p><strong>Schema Extender:</strong> LocalBusiness is enabled but the street address is empty. Fill address under <em>Settings → Schema Extender</em>.</p></div>';
                }
            }
        });
    }

    /* ------------- Inject FAQ into Yoast Graph ------------- */

    public function inject_faq_into_graph($graph, $context){
        if (!is_singular()) return $graph;
        $post_id = get_the_ID();
        if (!$post_id) return $graph;

        $s = get_option(self::OPT_KEY, []);
        $pt = get_post_type($post_id);
        $allowed_pts = isset($s['faq_post_types']) && is_array($s['faq_post_types']) ? $s['faq_post_types'] : [];
        if (empty($allowed_pts) || !in_array($pt, $allowed_pts, true)) return $graph;

        $enabled = get_post_meta($post_id, '_yse_faq_enabled', true) === '1';
        if (!$enabled) return $graph;

        $raw = get_post_meta($post_id, '_yse_faq_items', true);
        if (!$raw) return $graph;
        $items = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items) || count($items) < 2) return $graph;

        // If a FAQPage already exists (e.g., Yoast FAQ block), do nothing to avoid duplication.
        foreach ($graph as $node){
            $t = $node['@type'] ?? '';
            if ($t === 'FAQPage' || (is_array($t) && in_array('FAQPage', $t, true))) {
                return $graph;
            }
        }

        $faq_id = trailingslashit(get_permalink()).'#/schema/faq';
        $web_id = trailingslashit(get_permalink()).'#/schema/webpage';

        $questions = [];
        foreach ($items as $p){
            $q = isset($p['q']) ? wp_strip_all_tags($p['q']) : '';
            $a = isset($p['a']) ? (string)$p['a'] : '';
            if ($q === '' || $a === '') continue;

            $a = wp_kses($a, [
                'p'=>[], 'br'=>[], 'strong'=>[], 'em'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[],
                'a'=>['href'=>[], 'title'=>[], 'rel'=>[], 'target'=>[]],
            ]);

            $questions[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $a,
                ],
            ];
            if (count($questions) >= 20) break;
        }

        if (count($questions) < 2) return $graph;

        $faq_node = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            '@id'        => $faq_id,
            'mainEntity' => $questions,
            'isPartOf'   => [ '@id' => $web_id ],
        ];

        $graph[] = $faq_node;

        // Link WebPage.hasPart → FAQ
        foreach ($graph as &$node){
            $t = $node['@type'] ?? '';
            $id= $node['@id']   ?? '';
            if ($id === $web_id && ($t === 'WebPage' || (is_array($t) && in_array('WebPage',$t,true)))){
                $existing = isset($node['hasPart']) ? (array)$node['hasPart'] : [];
                $existing[] = ['@id' => $faq_id];
                $seen = []; $dedup = [];
                foreach ($existing as $it){
                    $k = is_array($it) && isset($it['@id']) ? $it['@id'] : wp_json_encode($it);
                    if (!isset($seen[$k])){ $seen[$k]=true; $dedup[] = $it; }
                }
                $node['hasPart'] = $dedup;
                break;
            }
        }
        unset($node);

        return $graph;
    }
}

/**
 * === Access Note (Client-Proofing) ===
 * The settings page is intentionally hidden from wp-admin menus for client safety.
 * Direct URL (replace domain): https://example.com/wp-admin/options-general.php?page=yse-settings
 * Admin path (relative): /wp-admin/options-general.php?page=yse-settings
 * To re-enable menu discovery, remove the hooks below.
 */

// Hide the "Schema Extender" submenu under Settings, while keeping the page reachable by direct URL.
add_action('admin_menu', function () {
    remove_submenu_page('options-general.php', 'yse-settings');
}, 999);

// Remove the Settings action link for this plugin on the Plugins screen (single-site).
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    foreach ($links as $k => $html) {
        if (strpos($html, 'page=yse-settings') !== false) {
            unset($links[$k]);
        }
    }
    return $links;
}, 99);

// Also remove the Settings link in Network Admin (multisite).
add_filter('network_admin_plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    foreach ($links as $k => $html) {
        if (strpos($html, 'page=yse-settings') !== false) {
            unset($links[$k]);
        }
    }
    return $links;
}, 99);

YSE_Agency_UI::instance();
