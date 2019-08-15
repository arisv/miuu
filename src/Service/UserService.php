<?php
namespace App\Service;


use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class UserService
{
    private $em;
    private $encoder;
    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $encoder)
    {
        $this->em = $em;
        $this->encoder = $encoder;
    }

    public function createUser($userData)
    {
        $user = new User();
        $user->setLogin($userData['login']);
        $user->setEmail($userData['email']);
        $user->setPassword($this->encoder->encodePassword(
            $user,
            $userData['password']
        ));
        $user->setRemoteToken($this->generateToken());
        $user->setRole(User::ROLE_USER);
        $user->setActive(true);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function generateToken()
    {
        do {
            $token = bin2hex(openssl_random_pseudo_bytes(16));
            $exists = $this->em->getRepository(User::class)->findOneBy([
                'remoteToken' => $token
            ]);
        } while ($exists);
        return $token;
    }
}