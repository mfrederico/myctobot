<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="card-title text-center mb-4">Create Account</h2>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= h($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="/auth/doregister">
                        <?php
                        // Include CSRF token if available
                        if (isset($csrf) && is_array($csrf)):
                            foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
                            <?php endforeach;
                        endif;
                        ?>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   value="<?= h($data['username'] ?? '') ?>"
                                   required 
                                   minlength="3"
                                   placeholder="Choose a username">
                            <small class="form-text text-muted">At least 3 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email" 
                                   value="<?= h($data['email'] ?? '') ?>"
                                   required 
                                   placeholder="your@email.com">
                            <small class="form-text text-muted">We'll never share your email</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required 
                                   minlength="6"
                                   placeholder="Create a password">
                            <small class="form-text text-muted">At least 6 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Confirm Password</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="password_confirm" 
                                   name="password_confirm" 
                                   required 
                                   minlength="6"
                                   placeholder="Confirm your password">
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-person-plus"></i> Create Account
                            </button>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p>Already have an account? <a href="/auth/login">Sign In</a></p>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-3 text-muted">
                <small>
                    <i class="bi bi-info-circle"></i> 
                    By registering, you agree to our <a href="/terms">Terms</a> and <a href="/privacy">Privacy Policy</a>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    margin-top: 50px;
    border-radius: 10px;
}

.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}
</style>