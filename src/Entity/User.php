<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity()
 * @ORM\Table(name="users")
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: "users")]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    const ROLE_USER = 1;
    const ROLE_ADMIN = 2;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private $id;
    /**
     * @ORM\Column(type="string", name="login", unique=true)
     */
    private $login;
    /**
     * @ORM\Column(type="string", name="email", unique=true)
     */
    private $email;
    /**
     * @ORM\Column(type="text", name="password")
     */
    private $password;
    /**
     * @ORM\Column(type="string", name="remote_token")
     */
    private $remoteToken;
    /**
     * @ORM\Column(type="integer", name="role")
     */
    private $role;
    /**
     * @ORM\Column(type="boolean", name="active")
     */
    private $active;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
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

    /**
     * @param mixed $login
     */
    public function setLogin($login)
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

    /**
     * @param mixed $email
     */
    public function setEmail($email)
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

    /**
     * @param mixed $remoteToken
     */
    public function setRemoteToken($remoteToken)
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

    /**
     * @param mixed $role
     */
    public function setRole($role)
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

    /**
     * @param mixed $active
     */
    public function setActive($active)
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