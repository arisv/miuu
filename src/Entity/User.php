<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: "users")]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    final public const ROLE_USER = 1;
    final public const ROLE_ADMIN = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', name: 'login', unique: true)]
    private $login;
    #[ORM\Column(type: 'string', name: 'email', unique: true)]
    private $email;
    #[ORM\Column(type: 'text', name: 'password')]
    private $password;
    #[ORM\Column(type: 'string', name: 'remote_token')]
    private $remoteToken;
    #[ORM\Column(type: 'integer', name: 'role')]
    private $role;
    #[ORM\Column(type: 'boolean', name: 'active')]
    private $active;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    public function setId(mixed $id)
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getLogin()
    {
        return $this->login;
    }

    public function setLogin(mixed $login)
    {
        $this->login = $login;
    }

    /**
     * @return mixed
     */
    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail(mixed $email)
    {
        $this->email = $email;
    }

    /**
     * @return mixed
     */
    public function getRemoteToken()
    {
        return $this->remoteToken;
    }

    public function setRemoteToken(mixed $remoteToken)
    {
        $this->remoteToken = $remoteToken;
    }

    /**
     * @return mixed
     */
    public function getRole()
    {
        return $this->role;
    }

    public function setRole(mixed $role)
    {
        $this->role = $role;
    }

    /**
     * @return mixed
     */
    public function getActive()
    {
        return $this->active;
    }

    public function setActive(mixed $active)
    {
        $this->active = $active;
    }

    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->role == self::ROLE_ADMIN) {
            $roles[] = 'ROLE_ADMIN';
        }
        return $roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getSalt()
    {
    }

    public function getUsername()
    {
        return $this->getUserIdentifier();
    }

    public function eraseCredentials()
    {
    }


}