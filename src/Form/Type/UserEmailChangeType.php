<?php

namespace App\Form\Type;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UserEmailChangeType extends AbstractType
{
    private EntityManagerInterface $em;
    private ?User $currentUser = null;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->em = $options['entity_manager'];
        $this->currentUser = $options['current_user'];

        $builder
            ->add('email', Type\EmailType::class, [
                'label' => 'New email',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Callback([$this, 'checkUniqueEmail'])
                ]
            ])
            ->add('currentPassword', Type\PasswordType::class, [
                'label' => 'Current password',
                'constraints' => [
                    new NotBlank(),
                    new UserPassword(['message' => 'Current password is incorrect'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['entity_manager', 'current_user']);
        $resolver->setAllowedTypes('entity_manager', EntityManagerInterface::class);
        $resolver->setAllowedTypes('current_user', User::class);
    }

    public function getBlockPrefix(): string
    {
        return 'user_email_change_type';
    }

    /**
     * Both identifiers are accepted at login, so the new email must be free across both columns,
     * ignoring the account being edited.
     */
    public function checkUniqueEmail($data, ExecutionContextInterface $context)
    {
        if ($data === null || $data === '') {
            return;
        }
        /** @var \App\Repository\UserRepository $users */
        $users = $this->em->getRepository(User::class);
        if ($users->isIdentifierTaken($data, $this->currentUser?->getId())) {
            $context->buildViolation('Email already in use')
                ->atPath('email')
                ->addViolation();
        }
    }
}
