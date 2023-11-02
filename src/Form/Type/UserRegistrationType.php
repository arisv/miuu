<?php

namespace App\Form\Type;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type as Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UserRegistrationType extends AbstractType
{
    /** @var $em EntityManager */
    private $em;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $this->em = $options['entity_manager'];
        $builder
            ->add('login', Type\TextType::class, [
                'label' => 'Username',
                'constraints' => [
                    new NotBlank(),
                    new Callback([$this, 'checkUniqueLogin'])
                ]
            ])
            ->add('email', Type\EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Callback([$this, 'checkUniqueEmail'])
                ]
            ])
            ->add('password', Type\RepeatedType::class, array(
                'type' => Type\PasswordType::class,
                'invalid_message' => 'Passwords mismatch',
                'required' => true,
                'first_options' => array(
                    'label' => 'Password',
                ),
                'second_options' => array(
                    'label' => 'Repeat password',
                ),
                'constraints' => [
                    new NotBlank(),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Your password should be at least {{ limit }} characters',
                        'max' => 4096,
                    ])
                ]
            ));

    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setRequired('entity_manager');
    }

    public function getBlockPrefix(): string
    {
        return 'user_registration_type';
    }

    public function checkUniqueEmail($data, ExecutionContextInterface $context)
    {
        $existing = $this->em->getRepository('App\Entity\User')->findOneBy([
            'email' => $data
        ]);

        if($existing)
        {
            $context->buildViolation('Email already in use')
                ->atPath('email')
                ->addViolation();
        }
    }

    public function checkUniqueLogin($data, ExecutionContextInterface $context)
    {
        $existing = $this->em->getRepository('App\Entity\User')->findOneBy([
            'login' => $data
        ]);

        if($existing)
        {
            $context->buildViolation('Login already in use')
                ->atPath('login')
                ->addViolation();
        }
    }
}
