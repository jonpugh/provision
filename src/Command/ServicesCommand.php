<?php

namespace Aegir\Provision\Command;

use Aegir\Provision\Command;
use Aegir\Provision\Context;
use Aegir\Provision\Context\PlatformContext;
use Aegir\Provision\Context\ServerContext;
use Aegir\Provision\Context\SiteContext;
use Aegir\Provision\Service;
use Consolidation\AnnotatedCommand\CommandFileDiscovery;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ServicesCommand
 *
 * @package Aegir\Provision\Command
 */
class ServicesCommand extends Command
{

    /**
     * "list" (default), "add", "remove", or "configure"
     * @var string
     */
    protected $sub_command = '';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('services')
          ->setDescription('Manage the services attached to servers.')
          ->setHelp(
            'Use this command to add new services to servers, or to add service subscriptions to platforms and sites.'
          )
          ->setDefinition($this->getCommandDefinition());
    }

    /**
     * Generate the list of options derived from ProvisionContextType classes.
     *
     * @return \Symfony\Component\Console\Input\InputDefinition
     */
    protected function getCommandDefinition()
    {
        $inputDefinition = $this::getCommandOptions();
        $inputDefinition[] = new InputArgument(
          'context_name',
          InputArgument::REQUIRED,
          'Server to work on.'
        );
        $inputDefinition[] = new InputArgument(
          'sub_command',
          InputArgument::OPTIONAL,
          '"list" (default), "add", "remove", or "configure".',
          'list'
        );
        $inputDefinition[] = new InputArgument(
          'service',
          InputArgument::OPTIONAL,
          'http, db, etc.'
        );
        $inputDefinition[] = new InputArgument(
          'server',
          InputArgument::OPTIONAL,
          'The name of the server context to use for this service.'
        );
        $inputDefinition[] = new InputOption(
          'service_type',
          NULL,
          InputOption::VALUE_OPTIONAL,
          'The name of the service type to use.'
        );
        
        return new InputDefinition($inputDefinition);
    }
    
    /**
     * Load all server_options, site_options, and platform_options from Service classes.
     *
     * Invoked from SaveCommand as well.
     * @return array
     */
    public static function getCommandOptions() {
        $inputDefinition = [];
    
        // Load all service options
        $options = Context::getServiceOptions();
    
        // For each service type...
        foreach ($options as $service => $service_name) {
        
            $class = Service::getClassName($service);
        
            // Load option_documentation() into input options.
            foreach (Context::getContextTypeOptions() as $type => $type_name) {
                $method = "{$type}_options";
                foreach ($class::$method() as $option => $description) {
                    $description = "$type_name $service service: $description";
                    $inputDefinition[] = new InputOption($option, NULL, InputOption::VALUE_OPTIONAL, $description);
                }
            }
        
        }
        
        return $inputDefinition;
    }

    /**
     * Validate that the context specified is a server.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @throws \Exception
     */
    protected function initialize(
        InputInterface $input,
        OutputInterface $output
    ) {
        parent::initialize(
            $input,
            $output
        );
//        if (isset($this->context->type) && $this->context->type != 'server') {
//            throw new \Exception('Context must be a server.');
//        }

        $this->sub_command = $input->getArgument('sub_command');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $method = "execute_{$this->sub_command}";
        if (method_exists($this, $method)) {
            $this->$method($input, $output);
        }
    }

    /**
     * List all services attached to this server.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute_list(InputInterface $input, OutputInterface $output) {

        $this->io->comment("List Services");
        $this->context->showServices($this->io);
    }

    /**
     * Add a new service to a server.
     */
    protected function execute_add(InputInterface $input, OutputInterface $output)
    {
        // Ask which service.
        $this->io->comment("Add Services");
        $service = $this->input->getArgument('service')?
            $this->input->getArgument('service'):
            $this->io->choice('Which service?', $this->context->getServiceOptions());
        
        if (empty($service)) {
            throw new \Exception("Argument 'service' must not be empty.");
        }

        // If server, ask which service type.
        if ($this->context->type == 'server') {
            if (empty($this->context->getServiceTypeOptions($service))) {
                throw new \Exception("There was no class found for service $service. Create one named \\Aegir\\Provision\\Service\\{$service}Service");
            }
            
            // Check or ask for service_type option.
            if ($this->input->getOption('service_type')) {
                $service_type = $this->input->getOption('service_type');
                $this->io->comment("Using option service_type=". $service_type);
            }
            else {
                $service_type = $this->io->choice('Which service type?', $this->context->getServiceTypeOptions($service));
            }
    
            // If $service_type is still empty, throw an exception. Happens if using -n
            if (empty($service_type)) {
                throw new \Exception('Option --service_type must be specified.');
            }
            
            // Handle invalid service_type.
            if (!class_exists(Service::getClassName($service, $service_type))) {
                $types = Context::getServiceTypeOptions($service);
                throw new \Exception(strtr("Service type !type is invalid for service !service. Valid options are: !types", [
                    '!service' => $service,
                    '!type' => $service_type,
                    '!types' => implode(", ", array_keys($types))
                ]));
    
            }

            // Then ask for all options.
            $properties = $this->askForServiceProperties($service);

            $this->io->info("Adding $service service $service_type...");

            $services_key = 'services';
            $service_info = [
                'type' => $service_type,
            ];
        }
        // All other context types are associating with servers that provide the service.
        else {
            if (empty($this->getApplication()->getServerOptions($service))) {
                throw new \Exception("No servers providing $service service were found. Create one with `provision save` or use `provision services` to add to an existing server.");
            }
            
            $server = $this->input->getArgument('server')?
                $this->input->getArgument('server'):
                $this->io->choice('Which server?', $this->getApplication()->getServerOptions($service));

            // Then ask for all options.
            $server_context = $this->getApplication()->getContext($server);
            $properties = $this->askForServiceProperties($service);

            $this->io->info("Using $service service from server $server...");

            $services_key = 'service_subscriptions';
            $service_info = [
                'server' => $server,
            ];
        }

        try {
            $this->context->config[$services_key][$service] = $service_info;
            if (!empty($properties)) {
                $this->context->config[$services_key][$service]['properties'] = $properties;
            }
            $this->context->save();
            $this->io->success('Service saved to Context!');
        }
        catch (\Exception $e) {
            throw new \Exception("Something went wrong when saving the context: " . $e->getMessage());
        }
    }

    /**
     * Loop through this context type's option_documentation() method and ask for each property.
     *
     * @return array
     */
    private function askForServiceProperties($service) {

        $class = $this->context->getAvailableServices($service);
        $method = "{$this->context->type}_options";

        $options = $class::{$method}();
        $properties = [];
        foreach ($options as $name => $description) {
            // If option does not exist, ask for it.
            if (!empty($this->input->getOption($name))) {
                $properties[$name] = $this->input->getOption($name);
                $this->io->comment("Using option {$name}={$properties[$name]}");
            }
            else {
                $properties[$name] = $this->io->ask("$name ($description)");
            }
        }
        return $properties;
    }
}
