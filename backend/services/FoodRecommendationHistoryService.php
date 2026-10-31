<?php

declare(strict_types=1);

final class FoodRecommendationHistoryService
{
	public static function save(PDO $database, int $userId, ?string $mealType, array $context, array $recommendations, string $provider, string $model): int
	{
		$mealTypes = ['breakfast', 'lunch', 'snack', 'dinner'];
		if (count($recommendations) !== count($mealTypes)) {
			throw new InvalidArgumentException('A complete daily meal plan is required.');
		}
		$recommendationMealTypes = array_map(static fn (mixed $recommendation): mixed => is_array($recommendation) ? ($recommendation['meal_type'] ?? null) : null, $recommendations);
		if ($recommendationMealTypes !== $mealTypes) {
			throw new InvalidArgumentException('Daily meal plan meal types are invalid.');
		}

		$generation = $database->prepare(
			'INSERT INTO ai_food_recommendation_generations
			 (user_id, requested_meal_type, daily_context, generated_by, ai_model)
			 VALUES (:user_id, :requested_meal_type, :daily_context, :generated_by, :ai_model)'
		);
		$historyContext = $context;
		unset($historyContext['health_constraints']['allergies']);
		$generation->execute([
			'user_id' => $userId,
			'requested_meal_type' => $mealType,
			'daily_context' => json_encode(array_merge($historyContext, [
				'calorie_target' => $context['nutrition_targets']['calories'] ?? null,
				'calories_consumed' => $context['today_consumed']['calories'] ?? null,
				'calories_remaining' => $context['today_remaining']['calories'] ?? null,
				'protein_target' => $context['nutrition_targets']['protein_g'] ?? null,
				'protein_consumed' => $context['today_consumed']['protein_g'] ?? null,
				'protein_remaining' => $context['today_remaining']['protein_g'] ?? null,
			]), JSON_THROW_ON_ERROR),
			'generated_by' => $provider,
			'ai_model' => $model,
		]);

		$generationId = (int) $database->lastInsertId();
		$item = $database->prepare(
			'INSERT INTO ai_food_recommendations
			 (generation_id, recommendation_type, title, description, foods, calories, protein_g, carbohydrates_g, fat_g, fiber_g, reason, regional_relevance, preparation_guidance, nutrition_source)
			 VALUES (:generation_id, :recommendation_type, :title, :description, :foods, :calories, :protein_g, :carbohydrates_g, :fat_g, :fiber_g, :reason, :regional_relevance, :preparation_guidance, :nutrition_source)'
		);

		foreach ($recommendations as $recommendation) {
			$nutrition = $recommendation['estimated_nutrition'];
			$item->execute([
				'generation_id' => $generationId,
				'recommendation_type' => $recommendation['meal_type'],
				'title' => $recommendation['title'],
				'description' => $recommendation['description'],
				'foods' => json_encode($recommendation['foods'], JSON_THROW_ON_ERROR),
				'calories' => $nutrition['calories'],
				'protein_g' => $nutrition['protein_g'],
				'carbohydrates_g' => $nutrition['carbohydrates_g'],
				'fat_g' => $nutrition['fat_g'],
				'fiber_g' => $nutrition['fiber_g'],
				'reason' => $recommendation['reason'],
				'regional_relevance' => $recommendation['regional_relevance'],
				'preparation_guidance' => $recommendation['preparation_guidance'],
				'nutrition_source' => $recommendation['nutrition_source'],
			]);
		}

		return $generationId;
	}

	public static function history(PDO $database, int $userId, int $limit, int $offset): array
	{
		$statement = $database->prepare(
			'SELECT g.id AS generation_id, g.requested_at, g.requested_meal_type, g.daily_context, g.generated_by, g.ai_model,
					r.id, r.recommendation_type, r.title, r.description, r.foods, r.calories, r.protein_g, r.carbohydrates_g, r.fat_g, r.fiber_g, r.reason,
					r.regional_relevance, r.preparation_guidance, r.nutrition_source
			 FROM (
				 SELECT id
				 FROM ai_food_recommendation_generations
				 WHERE user_id = :page_user_id
				 ORDER BY requested_at DESC, id DESC
				 LIMIT :limit OFFSET :offset
			 ) page
			 INNER JOIN ai_food_recommendation_generations g ON g.id = page.id
			 INNER JOIN ai_food_recommendations r ON r.generation_id = g.id
			 WHERE g.user_id = :user_id
			 ORDER BY g.requested_at DESC, g.id DESC, r.id ASC
			'
		);
		$statement->bindValue(':page_user_id', $userId, PDO::PARAM_INT);
		$statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
		$statement->bindValue(':limit', $limit, PDO::PARAM_INT);
		$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
		$statement->execute();

		$generations = [];
		while ($row = $statement->fetch()) {
			$generationId = (int) $row['generation_id'];
			if (!isset($generations[$generationId])) {
				$generations[$generationId] = [
					'generation_id' => $generationId,
					'generated_at' => $row['requested_at'],
					'requested_meal_type' => $row['requested_meal_type'],
					'daily_context' => json_decode($row['daily_context'], true),
					'generated_by' => $row['generated_by'],
					'ai_model' => $row['ai_model'],
					'recommendations' => [],
				];
			}
			$generations[$generationId]['recommendations'][] = [
				'id' => (int) $row['id'],
				'meal_type' => $row['recommendation_type'],
				'recommendation_type' => $row['recommendation_type'],
				'title' => $row['title'],
				'description' => $row['description'],
				'foods' => json_decode($row['foods'], true),
				'regional_relevance' => $row['regional_relevance'],
				'preparation_guidance' => $row['preparation_guidance'],
				'nutrition_source' => $row['nutrition_source'],
				'estimated_nutrition' => [
					'calories' => (float) $row['calories'],
					'protein_g' => (float) $row['protein_g'],
					'carbohydrates_g' => (float) $row['carbohydrates_g'],
					'fat_g' => (float) $row['fat_g'],
					'fiber_g' => (float) $row['fiber_g'],
				],
				'reason' => $row['reason'],
			];
		}

		return array_values($generations);
	}
}