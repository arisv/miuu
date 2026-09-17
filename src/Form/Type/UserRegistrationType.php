<?php

namespace App\Form\Type;

use App\Entity\User;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Form\Extension\Core\Type as Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UserRegistrationType extends AbstractType
{
    /** @var $em EntityManager */
    private $em;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->em = $options['entity_manager'];
        $builder
            ->add('login', Type\TextType::class, [
                'label' => 'Username',
                'constraints' => [
                    new NotBlank(),
                    new Length([
                        'min' => 3,
                        'max' => 32,
                        'minMessage' => 'Username should be at least {{ limit }} characters',
                        'maxMessage' => 'Username should be at most {{ limit }} characters'
                    ]),
                    // No "@" so a username can never be mistaken for an email at login.
                    new Regex([
                        'pattern' => '/^[A-Za-z0-9_.-]+$/',
                        'message' => 'Username may only contain letters, digits, dots, dashes and underscores'
                    ]),
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

        if ($options['with_role']) {
            $builder->add('role', Type\ChoiceType::class, [
                'label' => 'Role',
                'choices' => [
                    'User' => User::ROLE_USER,
                    'Admin' => User::ROLE_ADMIN
                ],
                'data' => User::ROLE_USER
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('entity_manager');
        // Admin-side user creation exposes the role picker; public signup does not.
        $resolver->setDefault('with_role', false);
        $resolver->setAllowedTypes('with_role', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'user_registration_type';
    }

    /**
     * Both identifiers are accepted at login, so each must be unique across both columns.
     */
    public function checkUniqueEmail($data, ExecutionContextInterface $context)
    {
        if ($this->identifierTaken($data)) {
            $context->buildViolation('Email already in use')
                ->atPath('email')
                ->addViolation();
        }
    }

    public function checkUniqueLogin($data, ExecutionContextInterface $context)
    {
        if ($this->identifierTaken($data)) {
            $context->buildViolation('Username already in use')
                ->atPath('login')
                ->addViolation();
        }
    }

    private function identifierTaken($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        return (bool) $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.login = :value OR u.email = :value')
            ->setParameter('value', $value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
