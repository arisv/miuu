<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserPasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', Type\PasswordType::class, [
                'label' => 'Current password',
                'constraints' => [
                    new NotBlank(),
                    new UserPassword(['message' => 'Current password is incorrect'])
                ]
            ])
            ->add('newPassword', Type\RepeatedType::class, [
                'type' => Type\PasswordType::class,
                'invalid_message' => 'Passwords mismatch',
                'required' => true,
                'first_options' => ['label' => 'New password'],
                'second_options' => ['label' => 'Repeat new password'],
                'constraints' => [
                    new NotBlank(),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Your password should be at least {{ limit }} characters',
                        'max' => 4096,
                    ])
                ]
            ]);
    }

    public function getBlockPrefix(): string
    {
        return 'user_password_change_type';
    }
}
