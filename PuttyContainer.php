<?php

namespace Putty;

use \Putty\Exceptions;

abstract class PuttyContainer extends Syntax\ModuleRegistrationSyntax {
    private $ClassBindings = array();
    private $ConstantBindings = array();
    
    final public static function Instance() {
        static $Instance = null;
        if($Instance === null)
                $Instance = new static();
        return $Instance;
    }
    
    final private function __construct() {
        $this->Initialize();
    }

    protected abstract function RegisterModules();
    
    private function Initialize() {
        $this->RegisterModules();
        foreach ($this->Modules as $Module) {
            if(!($Module instanceof PuttyModule))
                throw new Exceptions\InvalidModuleException();
            
            foreach ($Module->GetClassBindings() as $Binding) {
                $this->AddClassBinding($Binding);
            }
            
            foreach ($Module->GetConstantBindings() as $ConstantBinding) {
                $this->AddConstantBinding($ConstantBinding);
            }
        }
    }
    
    private function GetAllBindings() {
        return array_merge($this->ClassBindings, $this->ConstantBindings);
    }

    private function AddClassBinding(Bindings\ClassBinding $ClassBinding) {
        $this->VerifyNotAmbiguousBinding($ClassBinding);
        $this->ClassBindings[] = $ClassBinding;
    }
    
    private function AddConstantBinding(Bindings\ConstantBinding $ConstantBinding) {
        $this->VerifyNotAmbiguousBinding($ConstantBinding);
        $this->ConstantBindings[] = $ConstantBinding;
    }
    
    private function VerifyNotAmbiguousBinding(Bindings\ConstrainedBinding $Binding) {
        $ParentName = $Binding->GetParentType();
        foreach ($this->GetAllBindings() as $OtherBinding) {
            if($OtherBinding->GetParentType() === $ParentName) {
                if(!$Binding->IsConstrained() && !$Binding->IsConstrained()){
                    throw new Exceptions\AmbiguousBindingsException(
                            'Multiple unconstrained bindings to type: ' . $ParentName);
                }
            }
        }
    }
    
    private function GetMatchedBinding($Class, $ParentType) {
        $MatchedBinding = null;
        foreach ($this->GetAllBindings() as $Binding) {
            if($Binding->GetParentType() === $ParentType) {
                if($Binding->Matches($Class)) {
                    $MatchedBinding = $Binding;
                }
                if ($Binding->ExactlyMatches($Class)) {
                    $MatchedBinding = $Binding;
                    break;
                }
            }
        }
        
        return $MatchedBinding;
    }
    
    public function Resolve($Class) {
        try
        {
            $Reflection = new \ReflectionClass($Class);
            
            $MatchedBinding = $this->GetMatchedBinding(null, $Class);
            if($MatchedBinding !== null)
                return $this->ResolveBinding($MatchedBinding);
            
            if(!$Reflection->isInstantiable())
                throw new Exceptions\UnresolveableClassException($Class, 
                        'Class Must be instantiable');

            $ConstructorInfo = $Reflection->getConstructor();
            $ConstructorParameters = $ConstructorInfo->getParameters();

            $ResolvedConstructorParameters = $this->ResolveConstructorParameters
                    ($Class, $ConstructorParameters);

            return $Reflection->newInstanceArgs($ResolvedConstructorParameters);
        }
        catch (ReflectionException $ReflectionException) {
            throw new Exceptions\UnresolveableClassException($Class, null, $ReflectionException);
        }
    }
    
    private function ResolveBinding(Bindings\Binding $Binding) {
        if($Binding instanceof Bindings\ClassBinding)
            return $this->ResolveClassBinding($Binding);
        
        if($Binding instanceof Bindings\ConstantBinding)
            return $this->ResolveConstantBinding ($Binding);
    }
    
    private function ResolveClassBinding(Bindings\ClassBinding $Binding) {
        if($Binding->GetLifecycle()->IsResolved())
            return $Binding->GetLifecycle()->GetInstance();
        
        $Binding->GetLifecycle()->ResolveInstanceFactory(
                $this->CreateClassInstanceFactory($Binding));
        
        return $Binding->GetLifecycle()->GetInstance();
    }
    
    private function ResolveConstantBinding(Bindings\ConstantBinding $Binding) {
        return $Binding->BoundTo();
    }
    
    private function CreateClassInstanceFactory(Bindings\ClassBinding $Binding) {
        $Reflection = new \ReflectionClass($Binding->BoundTo());
        
        $ConstructorInfo = $Reflection->getConstructor();
        $Factory = function () use(&$Binding, &$Reflection, &$ConstructorInfo) {
            if($ConstructorInfo === null)
                return $Reflection->newInstance();

            $ConstructorParameters = $ConstructorInfo->getParameters();

            $ResolvedConstructorParameters = $this->ResolveConstructorParameters
                    ($Reflection->getName(), $ConstructorParameters, 
                    $Binding->GetConstantConstructorArgs());

            return $Reflection->newInstanceArgs($ResolvedConstructorParameters);
        };
        
        return $Factory;
    }
    
    private function ResolveConstructorParameters($Class, array $ConstructorParameters, 
            array $ConstantConstructorParameters = array()) {
        $ResolvedConstructorParameters = array();
        foreach ($ConstructorParameters as $ConstructorParameter) {
            if(array_key_exists($ConstructorParameter->name, $ConstantConstructorParameters)) {
                $ResolvedConstructorParameters[] = 
                        $ConstantConstructorParameters[$ConstructorParameter->name];
                continue;
            }
            
            if($ConstructorParameter->isOptional())
                continue;
            
            $ParameterType = $ConstructorParameter->getClass();
            if($ParameterType === null)
                throw new Exceptions\UnresolveableClassException($Class, 
                        'There is no defined parameter type or default value for constructor 
                            parameter: ' . $ConstructorParameter->name);
            
            $MatchedBinding = $this->GetMatchedBinding($Class, 
                    $ParameterType->getName());
            if($MatchedBinding === null)
                throw new Exceptions\UnresolveableClassException($Class, 
                        'Could not find a suitable binding for constructor parameter: ' . 
                        $ParameterType->getName());
            
            $ResolvedInstance = $this->ResolveBinding($MatchedBinding);
            $ResolvedConstructorParameters[] = $ResolvedInstance;
        }
        
        return $ResolvedConstructorParameters;
    }
}

?>
