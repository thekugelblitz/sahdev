<?php if($records): ?>
<div class="rr-evidence"><h4>Recorded acceptance evidence</h4><ul><?php $__currentLoopData = $records; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $record): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($record['reference']); ?> · <?php echo e($record['reviewer']); ?> · <?php echo e($record['reviewed_on']); ?><small><?php echo e($record['environment']); ?> / <?php echo e($record['version']); ?></small></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></ul></div>
<?php else: ?>
<p class="rr-evidence-empty">Acceptance evidence not yet recorded.</p>
<?php endif; ?>
<?php /**PATH E:\MainStream\Code\sahdev-main\saas\resources\views/readiness-evidence.blade.php ENDPATH**/ ?>