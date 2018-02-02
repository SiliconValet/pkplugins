<?php

/**
 * Class PatternkitDrupalTwigLib.
 */
class PatternkitDrupalTwigLib extends PatternkitDrupalCachedLib {

  private $id;

  private $path;

  private $title;

  /**
   * PatternkitDrupalTwigLib constructor.
   *
   * @param string $title
   *   The name of the library.
   * @param string $path
   *   The path to the library.
   */
  public function __construct($title, $path) {
    $this->title = $title;
    $this->id = preg_replace('/[^a-z0-9]+/', '_', strtolower($title));
    $this->path = $path;
  }

  /**
   * Fetches all assets for a pattern.
   *
   * @param \PatternkitPattern $pattern
   *   The pattern to use for asset retrieval.
   * @param \PatternkitEditorConfig $config
   *   The configuration object to use for provisioning the pattern.
   *
   * @return \PatternkitPattern
   *   The pattern parameter with updated asset references.
   */
  public function fetchPatternAssets(PatternkitPattern $pattern,
    \PatternkitEditorConfig $config) {
    // @todo Add support for twig lib attachments such as JS and images.
    $pattern->attachments = array();
    return $pattern;
  }

  /**
   * Returns the id of the Pattern Library.
   *
   * @return string
   *   The Pattern Library id.
   */
  public function getId() {
    return $this->id;
  }

  /**
   * Returns the title of the Pattern Library.
   *
   * @return string
   *   The Pattern Library title.
   */
  public function getTitle() {
    return $this->title;
  }

  /**
   * Returns renderable data or markup for a pattern editor.
   *
   * @param string|null $subtype
   *   If specified, return an editor customized for this subtype.
   * @param \PatternkitEditorConfig $config
   *   Optional configuration settings for the editor.
   *
   * @return mixed
   *   The renderable pattern editor.
   */
  public function getEditor($subtype = NULL,
    PatternkitEditorConfig $config = NULL) {
    $pattern = $this->getMetadata($subtype);
    if ($subtype === NULL || empty($pattern)) {
      return t('Unable to lookup the schema for this subtype.');
    }

    $schema_json = drupal_json_encode($pattern->schema);
    $starting_json = $config !== NULL ? drupal_json_encode($config->fields)
      : $config;
    // @todo Move to own JS file & Drupal Settings config var.
    $markup = <<<HTML
<div id="magic-pixie-dust"></div>
<script type="text/javascript">
  let target = document.getElementById("magic-pixie-dust");
  let shadow = target.attachShadow({mode: 'open'});

  shadow.innerHTML = '<link rel="stylesheet" id="theme_stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.3/css/bootstrap.min.css"><link rel="stylesheet" id="icon_stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/font-awesome/4.0.3/css/font-awesome.css"><div id="editor_holder"></div>';

  var data = {};
  data.schema = $schema_json;
  data.starting = $starting_json;
  // Enlarge the ctools modal to make it easier to work with the iframe.
  jQuery('.ctools-modal-content').animate({width:'100%', height:'100%'});
  jQuery('#modalContent').animate({'width': '100%', 'left':'0px', 'top':'0px'});
  jQuery('#modal-content').animate({'width': '100%', 'height': '100%'});

  if (data.starting !== null && data.starting.name) {
    JSONEditor.defaults.options.startval = data.starting;
  }
  
  // Initialize the editor with a JSON schema
  var editor = new JSONEditor(
    target.shadowRoot.getElementById('editor_holder'), {
      schema:            data.schema,
      theme:             'bootstrap3',
      iconlib:           'fontawesome4',
      keep_oneof_values: false,
      ajax:              true
    }
  );
  JSONEditor.plugins.sceditor.emoticonsEnabled = false;
  
  editor.on('change', function() {
    var config_string = JSON.stringify(editor.getValue());
    document.getElementById('schema_instance_config').value = config_string;
    
  });
</script>
HTML;

    return array(
      '#type'     => 'markup',
      '#markup'   => $markup,
      '#attached' => array(
        'library' => array(
          array('system', 'ui'),
        ),
        'js'      => array(
          drupal_get_path('module', 'patternkit') . '/js/jsoneditor.js',
        ),
      ),
    );
  }

  /**
   * Utility function to get all Patternkit module metadata.
   *
   * @return array
   *   Array of metadata objects found.
   */
  protected function getRawMetadata() {
    static $metadata;

    // Use static pattern to avoid rebuilding multiple times per request.
    if (is_null($metadata)) {

      $it = new RecursiveDirectoryIterator($this->path);
      $filter = ['json', 'twig'];
      $metadata = [];
      $components = [];

      /** @var \SplFileInfo $file */
      foreach (new RecursiveIteratorIterator($it) as $file) {
        // Skip directories and non-files.
        if (!$file->isFile()) {
          continue;
        }
        $file_path = $file->getPath();

        // Skip tests folders.
        if (strpos($file_path, '/tests') !== FALSE) {
          continue;
        }

        // Get the file extension for the file.
        $file_ext = $file->getExtension();
        if (!in_array(strtolower($file_ext), $filter, TRUE)) {
          continue;
        }

        // We use file_basename as a unique key, it is required that the
        // JSON and twig file share this basename.
        $file_basename = $file->getBasename('.' . $file_ext);

        // Build an array of all the filenames of interest, keyed by name.
        $components[$file_basename][$file_ext] = $file_path;
      }

      foreach ($components as $module_name => $data) {
        // If the component has a json file, create the pattern from it.
        if (!empty($data['json']) && $file_contents = file_get_contents($data['json'])) {
          $pattern = $this->createPattern(json_decode($file_contents));

          $subtype = "pk_$module_name";
          $pattern->subtype = $subtype;
          // URL is redundant for the twig based components.
          $pattern->url = $module_name;
        }
        else {
          // Create the pattern from defaults.
          $pattern = $this->createPattern(
            (object) [
              '$schema'    => 'http =>//json-schema.org/draft-04/schema#',
              'category'   => 'atom',
              'title'      => $module_name,
              'type'       => 'object',
              'format'     => 'grid',
              'properties' => (object) [],
              'required'   => [],
            ]
          );
        }

        if (!empty($data['twig'])) {
          $twig_file = $data['twig']
                       . DIRECTORY_SEPARATOR . $module_name . '.twig';
          if (file_exists($twig_file)) {
            $pattern->filename = $twig_file;
            $pattern->template = file_get_contents($twig_file);
          }
        }

        $metadata[$module_name] = $pattern;
      }

      foreach ($metadata as $pattern_type => $pattern) {
        // Replace any $ref links with relative paths.
        if (!isset($pattern->schema->properties)) {
          continue;
        }
        $pattern->schema->properties = _patternkit_schema_ref(
          $pattern->schema->properties,
          $metadata
        );
        $metadata[$pattern_type] = $pattern;
      }
    }

    return $metadata;
  }

  /**
   * Returns rendered markup for a provided pattern.
   *
   * @param \PatternkitPattern $pattern
   *   The pattern to render.
   * @param \PatternkitEditorConfig $config
   *   The editor configuration for the pattern.
   *
   * @return string
   *   The rendered pattern HTML.
   */
  public function getRenderedPatternMarkup(
    PatternkitPattern $pattern,
    PatternkitEditorConfig $config
  ) {
    if (empty($pattern->filename) || empty($config->fields)) {
      return '';
    }
    $template = $pattern->filename;
    $variables = $config->fields;

    return $this->renderTwigTemplate($template, $variables);
  }

  /**
   * Returns a singleton version of the twig template engine.
   *
   * @return Twig_Environment
   *   Twig environment object.
   *
   * @throws \Twig_Error_Loader
   *   Twig engine instance object.
   */
  public function getTwigInstance() {
    static $twig_engine;

    if (!is_object($twig_engine)) {
      // Setup twig environment.
      // @TODO: Properly libraryize this.
      require_once DRUPAL_ROOT . '/sites/all/libraries/Twig/Autoloader.php';
      Twig_Autoloader::register();

      $loader = new Twig_Loader_Filesystem();

      $metadata = $this->getRawMetadata();
      foreach ($metadata as $module_name => $module) {
        if (!empty($module->filename)) {
          $templatesDirectory = DRUPAL_ROOT . DIRECTORY_SEPARATOR . dirname(
              $module->filename
            );

          // Namespacing is not necessary here, as each "engine" is unique to
          // the "library".
          $loader->addPath($templatesDirectory);
        }
      }

      $twig_engine = new Twig_Environment(
        $loader,
        array(
          'autorender'  => (bool) variable_get('pktwig_auto_render', TRUE),
          'autoescape'  => (bool) variable_get('pktwig_auto_escape', FALSE),
          'auto_reload' => (bool) variable_get('pktwig_auto_reload', FALSE),
          'cache'       => variable_get('pktwig_template_cache_path', '/tmp/twig_compilation_cache'),
          'debug'       => (bool) variable_get('pktwig_debug', FALSE),
        )
      );
    }

    return $twig_engine;
  }

  /**
   * Renders a twig template on demand.
   *
   * @param string $template
   *   Template filename.
   * @param array $variables
   *   Variables to be assigned to template.
   *
   * @return string
   *   Rendered template.
   */
  public function renderTwigTemplate($template, array $variables = array()) {

    $content = '';
    if (file_exists($template)) {
      try {
        $twig     = $this->getTwigInstance();
        $template = $twig->loadTemplate(basename($template));
        $content  = $template->render($variables);
      }
      catch (Exception $e) {
        $content = t(
          'Twig error (!exc} "!error"',
          array(
            '!exc'   => 'tst',
            '!error' => $e->getMessage(),
          )
        );
        watchdog(
          'patternkit',
          'Twig engine failure: @msg',
          array(
            '@msg' => $e->getMessage(),
          ),
          WATCHDOG_ERROR
        );
      }
    }
    else {
      $content = t(
        'Template (!template) not found',
        array(
          '!template' => $template,
        )
      );
      watchdog(
        'patternkit',
        'Twig template not found: @msg',
        array(
          '@msg' => $template,
        ),
        WATCHDOG_ERROR
      );
    }

    return $content;
  }

}
