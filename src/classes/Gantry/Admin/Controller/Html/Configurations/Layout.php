<?php
namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Component\Config\BlueprintsForm;
use Gantry\Component\Config\Config;
use Gantry\Component\Controller\HtmlController;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout as LayoutObject;
use Gantry\Component\Request\Request;
use Gantry\Component\Response\JsonResponse;
use RocketTheme\Toolbox\Blueprints\Blueprints;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

class Layout extends HtmlController
{
    protected $httpVerbs = [
        'GET'    => [
            '/'         => 'index',
            '/create'   => 'create',
            '/create/*' => 'create',
            '/*'        => 'undefined',
            '/switch'   => 'listSwitches',
            '/switch/*' => 'switchLayout',
            '/preset'   => 'undefined',
            '/preset/*' => 'preset',
        ],
        'POST'   => [
            '/'                     => 'save',
            '/*'                    => 'undefined',
            '/*/*'                  => 'particle',
            '/particles'            => 'undefined',
            '/particles/*'          => 'undefined',
            '/particles/*/validate' => 'validate'
        ],
        'PUT'    => [
            '/*' => 'replace'
        ],
        'PATCH'  => [
            '/*' => 'update'
        ],
        'DELETE' => [
            '/*' => 'destroy'
        ]
    ];

    public function create($id = null)
    {
        if (!$id) {
            // TODO: we might want to display list of options here
            throw new \RuntimeException('Not Implemented', 404);
        }

        $layout = $this->getLayout("presets/{$id}");
        if (!$layout) {
            throw new \RuntimeException('Preset not found', 404);
        }
        $this->params['page_id'] = $id;
        $this->params['layout'] = $layout->toArray();

        return $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/create.html.twig', $this->params);
    }

    public function index()
    {
        $id = $this->params['configuration'];
        $layout = $this->getLayout($id);
        if (!$layout) {
            throw new \RuntimeException('Layout not found', 404);
        }

        $groups = [
            'Positions' => ['position' => [], 'spacer' => [], 'pagecontent' => []],
            'Particles' => ['particle' => []],
            'Atoms' => ['atom' => []]
        ];

        $particles = [
            'position'    => [],
            'spacer'      => [],
            'pagecontent' => [],
            'particle' => [],
            'atom' => []
        ];

        $particles = array_replace($particles, $this->getParticles());
        foreach ($particles as &$group) {
            asort($group);
        }

        foreach ($groups as $section => $children) {
            foreach ($children as $key => $child) {
                $groups[$section][$key] = $particles[$key];
            }
        }

        $this->params['page_id'] = $id;
        $this->params['layout'] = $layout->toArray();
        $this->params['id'] = ucwords(str_replace('_', ' ', ltrim($id, '_')));
        $this->params['particles'] = $groups;
        $this->params['switcher_url'] = str_replace('.', '/', 'configurations.' . $id . '.layout.switch');

        return $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/edit.html.twig', $this->params);
    }

    public function save()
    {
        $page = $this->params['configuration'];
        $title = isset($_POST['title']) ? $_POST['title'] : ucfirst($page);
        $layout = isset($_POST['layout']) ? json_decode($_POST['layout']) : null;

        if (!$layout) {
            throw new \RuntimeException('Error while saving layout: Structure missing', 400);
        }

        $new_page = preg_replace('|[^a-z0-9_-]|', '', strtolower($title));

        /** @var UniformResourceLocator $locator */
        $locator = $this->container['locator'];

        // Save layout into custom directory for the current theme.
        $save_dir = $locator->findResource('gantry-layouts://', true, true);
        $filename = $save_dir . "/{$new_page}.json";

        if ($page != $new_page && is_file($filename)) {
            throw new \RuntimeException("Error while saving layout: Layout '{$new_page}' already exists", 403);
        }

        $file = JsonFile::instance($filename);
        $file->save($layout);
    }

    public function particle($type, $id)
    {
        $page = $this->params['configuration'];
        $layout = $this->getLayout($page);
        if (!$layout) {
            throw new \RuntimeException('Layout not found', 404);
        }

        $item = $layout->find($id);
        $item->type    = isset($_POST['type']) ? $_POST['type'] : $type;
        $item->subtype = isset($_POST['subtype']) ? $_POST['subtype'] : null;
        $item->title   = isset($_POST['title']) ? $_POST['title'] : 'Untitled';
        if (!isset($item->attributes)) {
            $item->attributes = new \stdClass;
        }
        if (isset($_POST['block'])) {
            $item->block = (object) $_POST['block'];
        }

        $name = isset($item->subtype) ? $item->subtype : $type;

        $prefix = 'particles.' . $name;
        $defaults = (array) $this->container['config']->get($prefix);
        $attributes = isset($_POST['options']) && is_array($_POST['options']) ? $_POST['options'] : [];

        // TODO: Use blueprints to merge configuration.
        $item->attributes = (object) ($attributes + (array) $item->attributes + $defaults);

        if ($type == 'section' || $type == 'grid') {
            $extra = null;
            $blueprints = new BlueprintsForm(CompiledYamlFile::instance("gantry-admin://blueprints/layout/{$name}.yaml")->content());
        } else {
            $extra = new BlueprintsForm(CompiledYamlFile::instance("gantry-admin://blueprints/layout/block.yaml")->content());
            $blueprints = new BlueprintsForm($this->container['particles']->get($name));
        }

        $this->params += [
            'extra'         => $extra,
            'item'          => $item,
            'particle'      => $blueprints,
            'defaults'      => ['particle' => $defaults],
            'id'            => $name,
            'parent'        => 'settings',
            'route'         => 'settings.' . $prefix,
            'action'        => str_replace('.', '/', 'configurations.' . $page . '.layout.' . $prefix . '.validate'),
            'skip'          => ['enabled']
        ];

        if ($extra) {
            $typeLayout = $type == 'atom' ? $type : 'particle';
            $result = $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/' . $typeLayout . '.html.twig',
                $this->params);
        } else {
            $result = $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/section.html.twig',
                $this->params);
        }

        if (!empty($this->params['ajax'])) {
            return new JsonResponse(['html' => $result, 'defaults' => ['particle' => $defaults]]);
        }
        return $result;
    }

    public function listSwitches()
    {
        $this->params['presets'] = LayoutObject::presets();
        $result = $this->container['admin.theme']->render('@gantry-admin/layouts/switcher.html.twig', $this->params);
        return new JsonResponse(['html' => $result]);
    }

    public function switchLayout($id)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        $layout = $this->getLayout($id);
        if (!$layout->toArray()) {
            throw new \RuntimeException('Layout not found', 404);
        }

        return new JsonResponse(['data' => $layout->toArray()]);
    }

    public function preset($id)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        $preset = LayoutObject::preset($id);
        if (!$preset) {
            throw new \RuntimeException('Preset not found', 404);
        }

        return new JsonResponse(['data' => $preset]);
    }

    public function validate($particle)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        // Load particle blueprints and default settings.
        $validator = new Blueprints();

        if ($particle == 'section' || $particle == 'grid') {
            $type = $particle;
            $particle = null;
            $validator->embed('options', CompiledYamlFile::instance("gantry-admin://blueprints/layout/{$type}.yaml")->content());
            $defaults = [];
        } else {
            $type = in_array($particle, ['spacer', 'pagecontent', 'position']) ? $particle :  'particle';
            $validator->embed('options', $this->container['particles']->get($particle));
            $defaults = (array) $this->container['config']->get("particles.{$particle}");
        }

        // Create configuration from the defaults.
        $data = new Config(
            [
                'type'    => $type,
            ],
            function () use ($validator) {
                return $validator;
            }
        );

        /** @var Request $request */
        $request = $this->container['request'];

        // Join POST data.
        $data->join('options', $request->getArray('particle'));
        $data->set('options.enabled', (int) $data->get('options.enabled'));

        if ($particle) {
            if ($type != $particle) {
                $data->set('subtype', $particle);
            }

            $data->join('title', isset($_POST['title']) ? $_POST['title'] : ucfirst($particle));
            if (isset($_POST['block'])) {
                // TODO: remove empty items in some other way:
                $block = $request->getArray('block');
                foreach ($block as $key => $param) {
                    if ($param === '') {
                        unset($block[$key]);
                        continue;
                    }
                    if ($key == 'size') {
                        $block[$key] = (int) $param;
                    }
                }

                $data->join('block', $block);
            }
        }

        // TODO: validate

        return new JsonResponse(['data' => $data->toArray()]);
    }

    /**
     * @param string $name
     * @return LayoutObject
     */
    protected function getLayout($name)
    {
        return LayoutObject::instance($name);
    }

    protected function getParticles()
    {
        $config = $this->container['config'];

        $particles = $this->container['particles']->all();

        $list = [];
        foreach ($particles as $name => $particle) {
            $type = isset($particle['type']) ? $particle['type'] : 'particle';

            //if ($config->get("particles.{$name}.enabled", true)) {
                $list[$type][$name] = $particle['name'];
            //}
        }

        return $list;
    }
}
